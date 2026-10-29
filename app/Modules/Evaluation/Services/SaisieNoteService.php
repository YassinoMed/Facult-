<?php

namespace App\Modules\Evaluation\Services;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\Note;
use App\Models\Semestre;
use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\EvaluationCompostage;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\Evaluation\Models\NoteEpreuveHistorique;
use App\Services\AcademicCalculationService;
use App\Services\ArchiveService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Saisie massive des notes d'épreuve (§30) + synchronisation vers la
 * note officielle `notes` (unique source de vérité des moyennes).
 *
 * Toute valeur est re-contrôlée serveur : candidat convoqué, bornes
 * 0..note_max, absence explicite (pas de 0 implicite), statut workflow.
 */
class SaisieNoteService
{
    public function __construct(
        private AcademicCalculationService $calculs,
        private ArchiveService $archives,
        private RegleEvaluationService $regles,
    ) {}

    /**
     * Saisie par lot : transaction critique, rollback intégral en échec (§35).
     * Accepte soit `compostage`/`numero` (anonymisé), soit `etudiant_id` (compatibilité interne).
     * `$auteurId` NULL = saisie portail enseignant (aucun compte back-office :
     * les colonnes `saisi_par`/`auteur_id` sont NULLables) ; `$origine` préfixe
     * la traçabilité (ex. « Enseignant »).
     *
     * @param  array<int, array{compostage?: string, numero?: string, etudiant_id?: int, valeur: ?float, statut?: string, commentaire?: ?string, motif?: ?string}>  $lignes
     * @return array{saisies: int, ignorees: int, erreurs: array<int, string>}
     */
    public function saisirLot(Epreuve $epreuve, array $lignes, ?int $auteurId = null, ?string $origine = null): array
    {
        return DB::transaction(function () use ($epreuve, $lignes, $auteurId, $origine) {
            /** @var Epreuve $epreuveVerrouillee */
            $epreuveVerrouillee = Epreuve::whereKey($epreuve->id)->lockForUpdate()->firstOrFail();

            if ($epreuveVerrouillee->estVerrouillee()) {
                throw new DomainException('Épreuve verrouillée : saisie interdite.');
            }

            // Borne serveur de dernier recours : un barème absent ou nul
            // rend toute note ininterprétable. On refuse le lot AVANT
            // d'écrire quoi que ce soit (défense en profondeur derrière
            // la règle de validation `NoteDansBareme`).
            $noteMax = $epreuveVerrouillee->note_max !== null
                ? (float) $epreuveVerrouillee->note_max
                : 0.0;

            if (! is_finite($noteMax) || $noteMax <= 0) {
                throw new DomainException('Barème de l’épreuve invalide : la note maximale doit être strictement positive.');
            }

            $epreuveVerrouillee->loadMissing(['matiere', 'compostages']);
            $compostagesParNumero = $epreuveVerrouillee->compostages->keyBy(fn ($c) => mb_strtoupper(trim($c->numero)));

            $estPetitLot = count($lignes) <= 10;
            $etudiantIdsCibles = [];
            foreach ($lignes as $l) {
                if (isset($l['etudiant_id']) && is_numeric($l['etudiant_id'])) {
                    $etudiantIdsCibles[] = (int) $l['etudiant_id'];
                }
                $num = trim((string) ($l['compostage'] ?? $l['numero'] ?? ''));
                if ($num !== '') {
                    $comp = $compostagesParNumero->get(mb_strtoupper($num));
                    if ($comp) {
                        $etudiantIdsCibles[] = (int) $comp->etudiant_id;
                    }
                }
            }
            $etudiantIdsCibles = array_values(array_unique($etudiantIdsCibles));

            $candidatsQuery = Candidat::where('epreuve_id', $epreuveVerrouillee->id);
            $notesQuery = NoteEpreuve::where('epreuve_id', $epreuveVerrouillee->id)->lockForUpdate();

            if ($estPetitLot && ! empty($etudiantIdsCibles)) {
                $candidatsQuery->whereIn('etudiant_id', $etudiantIdsCibles);
                $notesQuery->whereIn('etudiant_id', $etudiantIdsCibles);
            }

            $candidatsParEtudiant = $candidatsQuery->get()->keyBy('etudiant_id');
            $notesExistantes = $notesQuery->get()->keyBy('etudiant_id');

            $saisies = 0;
            $ignorees = 0;
            $erreurs = [];

            $maintenant = now();
            $aInserer = [];
            $aMettreAJour = [];
            $historiquesAPersister = [];

            $etudiantsVus = [];

            foreach ($lignes as $index => $ligne) {
                // Résolution sécurisée : compostage en priorité, sinon etudiant_id interne
                $numCompostage = trim((string) ($ligne['compostage'] ?? $ligne['numero'] ?? ''));

                if ($numCompostage !== '') {
                    $comp = $compostagesParNumero->get(mb_strtoupper($numCompostage))
                        ?? EvaluationCompostage::where('epreuve_id', $epreuveVerrouillee->id)->where('numero', $numCompostage)->first();

                    if ($comp === null) {
                        $erreurs[$index] = "Numéro de compostage « {$numCompostage} » invalide pour cette épreuve.";

                        continue;
                    }

                    $etudiantId = (int) $comp->etudiant_id;
                } elseif (isset($ligne['etudiant_id'])) {
                    $etudiantId = (int) $ligne['etudiant_id'];
                } else {
                    $erreurs[$index] = 'Identifiant étudiant ou numéro de compostage requis.';

                    continue;
                }

                if (isset($etudiantsVus[$etudiantId])) {
                    $erreurs[$index] = "Doublon détecté dans le lot pour le candidat (étudiant #{$etudiantId}).";

                    continue;
                }
                $etudiantsVus[$etudiantId] = true;

                $candidat = $candidatsParEtudiant->get($etudiantId);

                if ($candidat === null) {
                    $erreurs[$index] = 'Étudiant non convoqué à cette épreuve.';

                    continue;
                }

                $valeur = $ligne['valeur'] ?? null;
                if ($valeur === '') {
                    $valeur = null;
                }

                if ($valeur !== null) {
                    if (! is_numeric($valeur)) {
                        $erreurs[$index] = 'La note doit être un nombre.';

                        continue;
                    }

                    $valeur = (float) $valeur;

                    if (! is_finite($valeur)) {
                        $erreurs[$index] = 'La note doit être un nombre fini.';

                        continue;
                    }

                    if (abs($valeur - round($valeur, 2)) > 1e-9) {
                        $erreurs[$index] = 'La note doit comporter au maximum deux décimales.';

                        continue;
                    }

                    $valeur = round($valeur, 2);
                }

                if ($candidat->estAbsent()) {
                    if ($valeur !== null) {
                        $erreurs[$index] = 'Candidat absent : aucune note ne peut être saisie.';
                    } else {
                        $ignorees++;
                    }

                    continue;
                }

                if ($valeur !== null && ($valeur < 0 || $valeur > $noteMax)) {
                    $erreurs[$index] = "Note hors bornes 0..{$noteMax}.";

                    continue;
                }

                /** @var NoteEpreuve|null $note */
                $note = $notesExistantes->get($candidat->etudiant_id);
                $estNouvelle = ($note === null);

                // F-02 : Si la note n'existe pas et qu'aucun chiffre n'est saisi, ne pas créer d'enregistrement fantôme vide
                if ($estNouvelle && $valeur === null) {
                    $ignorees++;

                    continue;
                }

                if ($note !== null && $note->exists && $note->estVerrouillee()) {
                    $ignorees++;

                    continue;
                }

                $ancienne = ($note !== null && $note->exists) ? $note->valeur : null;

                // F-01 : Si une note déjà validée est modifiée avec une note différente,
                // elle doit repasser en 'entered' pour exiger une nouvelle validation.
                $nouveauStatut = 'entered';
                if ($note !== null && $note->exists && $note->statut === 'validated' && (string) $ancienne === (string) $valeur) {
                    $nouveauStatut = 'validated';
                } elseif ($valeur === null) {
                    $nouveauStatut = 'draft';
                }

                if ($estPetitLot) {
                    if ($note === null) {
                        $note = new NoteEpreuve([
                            'epreuve_id' => $epreuveVerrouillee->id,
                            'etudiant_id' => $candidat->etudiant_id,
                        ]);
                    }

                    $note->fill([
                        'candidat_id' => $candidat->id,
                        'valeur' => $valeur,
                        'note_max' => $noteMax,
                        'coefficient' => $epreuveVerrouillee->coefficient,
                        'statut' => $nouveauStatut,
                        'commentaire' => $ligne['commentaire'] ?? $note->commentaire,
                        'saisi_par' => $auteurId,
                        'valide_par' => ($nouveauStatut === 'validated') ? $note->valide_par : null,
                    ]);
                    $note->save();
                    $notesExistantes->put($candidat->etudiant_id, $note);

                    if ((string) $ancienne !== (string) $note->valeur) {
                        NoteEpreuveHistorique::create([
                            'evaluation_note_id' => $note->id,
                            'ancienne_valeur' => $ancienne,
                            'nouvelle_valeur' => $note->valeur,
                            'motif' => $ligne['motif'] ?? ($origine ? "Saisie ({$origine})" : 'Saisie'),
                            'auteur_id' => $auteurId,
                            'created_at' => $maintenant,
                        ]);
                    }
                } else {
                    $donnees = [
                        'candidat_id' => $candidat->id,
                        'valeur' => $valeur,
                        'note_max' => $noteMax,
                        'coefficient' => $epreuveVerrouillee->coefficient,
                        'statut' => $nouveauStatut,
                        'commentaire' => $ligne['commentaire'] ?? ($note?->commentaire),
                        'saisi_par' => $auteurId,
                        'valide_par' => ($nouveauStatut === 'validated') ? $note?->valide_par : null,
                        'updated_at' => $maintenant,
                    ];

                    if ($estNouvelle) {
                        $donnees['epreuve_id'] = $epreuveVerrouillee->id;
                        $donnees['etudiant_id'] = $candidat->etudiant_id;
                        $donnees['created_at'] = $maintenant;
                        $aInserer[$candidat->etudiant_id] = $donnees;
                    } else {
                        $donnees['id'] = $note->id;
                        $donnees['epreuve_id'] = $epreuveVerrouillee->id;
                        $donnees['etudiant_id'] = $candidat->etudiant_id;
                        $aMettreAJour[$candidat->etudiant_id] = $donnees;
                    }

                    if ((string) $ancienne !== (string) $valeur) {
                        $historiquesAPersister[] = [
                            'etudiant_id' => $candidat->etudiant_id,
                            'evaluation_note_id' => $note?->id,
                            'ancienne_valeur' => $ancienne,
                            'nouvelle_valeur' => $valeur,
                            'motif' => $ligne['motif'] ?? ($origine ? "Saisie ({$origine})" : 'Saisie'),
                            'auteur_id' => $auteurId,
                            'created_at' => $maintenant,
                        ];
                    }
                }

                $saisies++;
            }

            if (! $estPetitLot) {
                if (! empty($aInserer)) {
                    foreach (array_chunk(array_values($aInserer), 500) as $chunk) {
                        DB::table('evaluation_notes')->insert($chunk);
                    }

                    $nouvellesCrees = NoteEpreuve::where('epreuve_id', $epreuveVerrouillee->id)
                        ->whereIn('etudiant_id', array_keys($aInserer))
                        ->get()
                        ->keyBy('etudiant_id');

                    foreach ($nouvellesCrees as $etudiantId => $noteCree) {
                        $notesExistantes->put($etudiantId, $noteCree);
                    }
                }

                if (! empty($aMettreAJour)) {
                    foreach (array_chunk(array_values($aMettreAJour), 500) as $chunk) {
                        NoteEpreuve::upsert(
                            $chunk,
                            ['epreuve_id', 'etudiant_id'],
                            ['candidat_id', 'valeur', 'note_max', 'coefficient', 'statut', 'commentaire', 'saisi_par', 'valide_par', 'updated_at']
                        );
                    }
                }

                if (! empty($historiquesAPersister)) {
                    $historiquesInserts = [];
                    foreach ($historiquesAPersister as $hist) {
                        $noteId = $hist['evaluation_note_id'] ?? $notesExistantes->get($hist['etudiant_id'])?->id;
                        if ($noteId) {
                            $historiquesInserts[] = [
                                'evaluation_note_id' => $noteId,
                                'ancienne_valeur' => $hist['ancienne_valeur'],
                                'nouvelle_valeur' => $hist['nouvelle_valeur'],
                                'motif' => $hist['motif'],
                                'auteur_id' => $hist['auteur_id'],
                                'created_at' => $hist['created_at'],
                            ];
                        }
                    }

                    if (! empty($historiquesInserts)) {
                        foreach (array_chunk($historiquesInserts, 500) as $chunk) {
                            DB::table('evaluation_notes_historique')->insert($chunk);
                        }
                    }
                }
            }

            Log::info('notes.lot_saisi', [
                'epreuve_id' => $epreuveVerrouillee->id,
                'saisies' => $saisies,
                'ignorees' => $ignorees,
                'auteur_id' => $auteurId,
                'origine' => $origine,
            ]);

            EvaluationChanged::dispatch('notes.saisies', $epreuveVerrouillee->id);

            return ['saisies' => $saisies, 'ignorees' => $ignorees, 'erreurs' => $erreurs];
        });
    }

    /**
     * Saisie anonyme (§8-§9) : les lignes portent un `numero` de
     * compostage, résolu côté serveur (jamais de `etudiant_id`
     * client). Toute la validation métier de `saisirLot` est
     * réutilisée à l'identique.
     *
     * @param  array<int, array{numero: string, valeur: ?float, commentaire?: ?string, motif?: ?string}>  $lignes
     * @return array{saisies: int, ignorees: int, erreurs: array<int, string>}
     */
    public function saisirLotParCompostages(Epreuve $epreuve, array $lignes, ?int $auteurId = null, ?string $origine = null): array
    {
        $compostages = EvaluationCompostage::where('epreuve_id', $epreuve->id)
            ->whereIn('numero', array_map(
                fn ($ligne) => mb_strtoupper(trim((string) ($ligne['numero'] ?? ''))),
                $lignes
            ))
            ->get()
            ->keyBy('numero');

        $converties = [];

        foreach ($lignes as $index => $ligne) {
            $numero = mb_strtoupper(trim((string) ($ligne['numero'] ?? '')));
            $compostage = $compostages->get($numero);

            if ($compostage === null) {
                $converties[$index] = ['etudiant_id' => -1, 'valeur' => $ligne['valeur'] ?? null];

                continue;
            }

            $converties[$index] = [
                'etudiant_id' => $compostage->etudiant_id,
                'valeur' => $ligne['valeur'] ?? null,
                'commentaire' => $ligne['commentaire'] ?? null,
                'motif' => ($origine !== null ? $origine.' ' : '').'Saisie anonyme '.$numero,
            ];
        }

        $resultat = $this->saisirLot($epreuve, array_values($converties), $auteurId, $origine);

        // Les lignes à etudiant_id = -1 échouent en « non convoqué » ;
        // on reformule sans exposer d'identifiant interne.
        $erreurs = [];

        foreach (array_keys($lignes) as $position => $index) {
            if (isset($resultat['erreurs'][$position])) {
                $numero = mb_strtoupper(trim((string) ($lignes[$index]['numero'] ?? '')));
                $erreurs[$index] = $compostages->has($numero)
                    ? $resultat['erreurs'][$position]
                    : "Numéro {$numero} inconnu pour cette épreuve.";
            }
        }

        return ['saisies' => $resultat['saisies'], 'ignorees' => $resultat['ignorees'], 'erreurs' => $erreurs];
    }

    /**
     * Validation d'une note d'épreuve → synchronise la note officielle
     * `notes` (création/mise à jour via ArchiveService : cohérence
     * matière/niveau/semestre, recalcul, audit sans PII).
     */
    public function valider(NoteEpreuve $note, int $validateurId, ?string $motif = null): NoteEpreuve
    {
        return DB::transaction(function () use ($note, $validateurId, $motif) {
            /** @var NoteEpreuve $noteVerrouillee */
            $noteVerrouillee = NoteEpreuve::whereKey($note->id)->lockForUpdate()->firstOrFail();
            $noteVerrouillee->loadMissing(['epreuve.matiere', 'etudiant', 'candidat']);

            return $this->validerNoteVerrouillee($noteVerrouillee, $validateurId, $motif)->fresh();
        });
    }

    /**
     * Cœur de validation, exécutable dans la transaction de `valider`
     * (verrou posé par l'appelant) ou dans celle de `validerTout` (Lot 7 :
     * pas de savepoint par note). `$inscriptionPrechargee` /
     * `$semestrePrecharge` sont résolus une fois par lot.
     */
    private function validerNoteVerrouillee(
        NoteEpreuve $noteVerrouillee,
        int $validateurId,
        ?string $motif,
        ?InscriptionAcademique $inscriptionPrechargee = null,
        ?Semestre $semestrePrecharge = null,
    ): NoteEpreuve {
        if ($noteVerrouillee->estVerrouillee()) {
            throw new DomainException('Note verrouillée.');
        }

        if ($noteVerrouillee->valeur === null) {
            throw new DomainException('Aucune valeur à valider.');
        }

        if (! $noteVerrouillee->peutTransiterVers('validated')) {
            throw new DomainException("Validation interdite depuis « {$noteVerrouillee->statut} ».");
        }

        if ($noteVerrouillee->candidat !== null && $noteVerrouillee->candidat->estAbsent()) {
            throw new DomainException('Candidat absent : validation interdite.');
        }

        $epreuve = $noteVerrouillee->epreuve;
        $etudiant = $noteVerrouillee->etudiant;

        $inscription = $inscriptionPrechargee ?? $this->inscriptionPourEpreuve($etudiant, $epreuve);
        $semestre = $semestrePrecharge ?? $this->semestrePourMatiere($inscription, $epreuve->matiere->semestreRelatif());

        $sessionOfficielle = $this->sessionOfficielle($epreuve);

        $officielle = Note::where('semestre_id', $semestre->id)
            ->where('matiere_id', $epreuve->matiere_id)
            ->where('session', $sessionOfficielle)
            ->lockForUpdate()
            ->first();

        // Verrou officiel indépendant (NotePolicy : verrouillée =
        // rôles supérieurs) : la voie épreuve ne doit jamais
        // réécrire une note officielle verrouillée. La voie
        // sanctionnée est la correction autorisée (qui déverrouille).
        if ($officielle !== null && $officielle->estVerrouillee()) {
            throw new DomainException('Note officielle verrouillée : demandez une correction autorisée.');
        }

        if ($officielle === null) {
            $officielle = $this->archives->saisirNote($inscription, [
                'semestre_numero' => $semestre->numero,
                'matiere_id' => (int) $epreuve->matiere_id,
                'note' => (float) $noteVerrouillee->valeur,
                'session' => $sessionOfficielle,
                'observation' => $motif,
            ]);
        } else {
            $this->archives->modifierNote($officielle, [
                'note' => (float) $noteVerrouillee->valeur,
                'raison' => $motif ?? 'Validation épreuve',
            ]);
        }

        // LOT 05 — auditabilité : horodatage de la DERNIÈRE validation
        // (révalidation écrase). Le drapeau d'audit
        // `a_ete_modifiee_apres_validation` s'appuie sur cette borne.
        // Garde fail-soft : si la colonne additive n'est pas encore
        // appliquée dans l'environnement, on n'écrit pas (jamais d'erreur).
        $donnees = [
            'note_id' => $officielle->id,
            'statut' => 'validated',
            'valide_par' => $validateurId,
        ];
        if (NoteEpreuve::existeColonneValideAt()) {
            $donnees['valide_at'] = now();
        }
        $noteVerrouillee->update($donnees);

        EvaluationChanged::dispatch('note.validee', $epreuve->id);

        return $noteVerrouillee;
    }

    /**
     * Resynchronise explicitement une note d'épreuve validée vers la table officielle (notes).
     */
    public function resynchroniserNoteOfficielle(NoteEpreuve $note, int $validateurId): NoteEpreuve
    {
        return $this->valider($note, $validateurId, 'Resynchronisation officielle automatique');
    }

    /**
     * Validation collective de toutes les notes saisies d'une épreuve (§14.4).
     *
     * @return array{validees: int, ignorees: int, erreurs: array<int, string>}
     */
    public function validerTout(Epreuve $epreuve, int $validateurId, ?string $motif = null): array
    {
        return DB::transaction(function () use ($epreuve, $validateurId, $motif) {
            /** @var Epreuve $epreuveVerrouillee */
            $epreuveVerrouillee = Epreuve::whereKey($epreuve->id)->lockForUpdate()->firstOrFail();

            if ($epreuveVerrouillee->estVerrouillee()) {
                throw new DomainException('Épreuve verrouillée : validation interdite.');
            }

            $notes = NoteEpreuve::where('epreuve_id', $epreuveVerrouillee->id)
                ->whereNotNull('valeur')
                ->whereIn('statut', ['entered', 'submitted'])
                ->lockForUpdate()
                ->get();

            if ($notes->isEmpty()) {
                return ['validees' => 0, 'ignorees' => 0, 'erreurs' => []];
            }

            // Perf (Lot 7) : inscriptions, semestres, candidats et
            // identités préchargés UNE fois pour tout le lot (plus de
            // savepoint ni de requêtes inscription/semestre par note).
            $epreuveVerrouillee->loadMissing(['matiere', 'session']);
            $etudiantIds = $notes->pluck('etudiant_id')->unique()->all();

            $inscriptionsParEtudiant = InscriptionAcademique::query()
                ->whereIn('etudiant_id', $etudiantIds)
                ->where('annee_universitaire_id', $epreuveVerrouillee->annee_universitaire_id)
                ->with('semestres')
                ->get()
                ->keyBy('etudiant_id');

            $candidatsParEtudiant = Candidat::where('epreuve_id', $epreuveVerrouillee->id)->get()->keyBy('etudiant_id');
            $etudiantsParId = Etudiant::whereIn('id', $etudiantIds)->get()->keyBy('id');

            $numeroMatiere = $epreuveVerrouillee->matiere->semestreRelatif();
            $numeroSemestre = in_array($numeroMatiere, [1, 2], true) ? $numeroMatiere : 1;

            $validees = 0;
            $ignorees = 0;
            $erreurs = [];

            foreach ($notes as $note) {
                $inscription = $inscriptionsParEtudiant->get($note->etudiant_id);
                $semestre = $inscription?->semestres->firstWhere('numero', $numeroSemestre);

                try {
                    $note->setRelation('epreuve', $epreuveVerrouillee);

                    $etudiant = $etudiantsParId->get($note->etudiant_id);
                    if ($etudiant !== null) {
                        $note->setRelation('etudiant', $etudiant);
                    } else {
                        $note->loadMissing('etudiant');
                    }

                    $candidat = $candidatsParEtudiant->get($note->etudiant_id);
                    if ($candidat !== null) {
                        $note->setRelation('candidat', $candidat);
                    } else {
                        $note->loadMissing('candidat');
                    }

                    $this->validerNoteVerrouillee(
                        $note,
                        $validateurId,
                        $motif ?? 'Validation collective',
                        $inscription,
                        $semestre,
                    );
                    $validees++;
                } catch (DomainException $e) {
                    $erreurs[] = "Copie #{$note->id} : ".$e->getMessage();
                }
            }

            return [
                'validees' => $validees,
                'ignorees' => $ignorees,
                'erreurs' => $erreurs,
            ];
        });
    }

    public function verrouiller(NoteEpreuve $note, int $auteurId, string $motif): NoteEpreuve
    {
        return DB::transaction(function () use ($note, $auteurId, $motif) {
            if ($note->statut !== 'validated') {
                throw new DomainException('Seule une note validée peut être verrouillée.');
            }

            $note->update(['statut' => 'locked', 'verrouillee_at' => now()]);

            NoteEpreuveHistorique::create([
                'evaluation_note_id' => $note->id,
                'ancienne_valeur' => $note->valeur,
                'nouvelle_valeur' => $note->valeur,
                'motif' => 'Verrouillage : '.$motif,
                'auteur_id' => $auteurId,
                'created_at' => now(),
            ]);

            if ($note->note_id !== null) {
                $officielle = Note::find($note->note_id);
                if ($officielle !== null && ! $officielle->estVerrouillee()) {
                    $this->archives->basculerVerrou($officielle, true);
                }
            }

            EvaluationChanged::dispatch('note.verrouillee', $note->epreuve_id);

            Log::info('note.locked', [
                'epreuve_id' => $note->epreuve_id,
                'note_id' => $note->id,
                'auteur_id' => $auteurId,
            ]);

            return $note->fresh();
        });
    }

    /**
     * Correction contrôlée d'une note verrouillée (§14.8) : demande
     * (motif obligatoire, sans changement d'état) puis autorisation
     * explicite qui rouvre la saisie. Tout est historisé.
     */
    public function demanderCorrection(NoteEpreuve $note, int $auteurId, string $motif): NoteEpreuve
    {
        return DB::transaction(function () use ($note, $auteurId, $motif) {
            if ($note->statut !== 'locked') {
                throw new DomainException('Seule une note verrouillée peut faire l’objet d’une demande de correction.');
            }

            NoteEpreuveHistorique::create([
                'evaluation_note_id' => $note->id,
                'ancienne_valeur' => $note->valeur,
                'nouvelle_valeur' => $note->valeur,
                'motif' => 'Demande de correction : '.$motif,
                'auteur_id' => $auteurId,
                'created_at' => now(),
            ]);

            EvaluationChanged::dispatch('note.correction_demandee', $note->epreuve_id);

            return $note->fresh();
        });
    }

    public function autoriserCorrection(NoteEpreuve $note, int $auteurId, string $motif): NoteEpreuve
    {
        return DB::transaction(function () use ($note, $auteurId, $motif) {
            $note->refresh();

            if (! $note->peutTransiterVers('correction_demandee')) {
                throw new DomainException("Correction interdite depuis « {$note->statut} ».");
            }

            $note->update(['statut' => 'correction_demandee', 'verrouillee_at' => null]);

            NoteEpreuveHistorique::create([
                'evaluation_note_id' => $note->id,
                'ancienne_valeur' => $note->valeur,
                'nouvelle_valeur' => $note->valeur,
                'motif' => 'Correction autorisée : '.$motif,
                'auteur_id' => $auteurId,
                'created_at' => now(),
            ]);

            if ($note->note_id !== null) {
                $officielle = Note::find($note->note_id);
                if ($officielle !== null && $officielle->estVerrouillee()) {
                    $this->archives->basculerVerrou($officielle, false);
                }
            }

            EvaluationChanged::dispatch('note.correction_autorisee', $note->epreuve_id);

            return $note->fresh();
        });
    }

    private function inscriptionPourEpreuve(Etudiant $etudiant, Epreuve $epreuve): InscriptionAcademique
    {
        $inscription = $etudiant->inscriptionsAcademiques()
            ->where('annee_universitaire_id', $epreuve->annee_universitaire_id)
            ->first();

        if ($inscription === null) {
            throw new DomainException('Aucune inscription académique pour cette année.');
        }

        return $inscription;
    }

    private function semestrePourMatiere(InscriptionAcademique $inscription, int $numeroMatiere): Semestre
    {
        $numero = in_array($numeroMatiere, [1, 2], true) ? $numeroMatiere : 1;

        $semestre = $inscription->semestres()->where('numero', $numero)->first();

        if ($semestre === null) {
            // Erreur métier lisible (lot 1) : jamais de ModelNotFoundException (500).
            throw new DomainException("Semestre S{$numero} introuvable sur l'inscription académique : note non rattachable.");
        }

        return $semestre;
    }

    private function sessionOfficielle(Epreuve $epreuve): string
    {
        $epreuve->loadMissing('session');

        return $epreuve->session !== null && $epreuve->session->type === 'rattrapage'
            ? 'rattrapage'
            : 'normale';
    }
}
