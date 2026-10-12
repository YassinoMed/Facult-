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
        return DB::transaction(function () use ($epreuve, $lignes, $auteurId) {
            if ($epreuve->estVerrouillee()) {
                throw new DomainException('Épreuve verrouillée : saisie interdite.');
            }

            $epreuve->loadMissing(['matiere', 'compostages']);
            $compostagesParNumero = $epreuve->compostages->keyBy('numero');

            $saisies = 0;
            $ignorees = 0;
            $erreurs = [];

            foreach ($lignes as $index => $ligne) {
                // Résolution sécurisée : compostage en priorité, sinon etudiant_id interne
                $numCompostage = trim((string) ($ligne['compostage'] ?? $ligne['numero'] ?? ''));

                if ($numCompostage !== '') {
                    $comp = $compostagesParNumero->get($numCompostage)
                        ?? EvaluationCompostage::where('epreuve_id', $epreuve->id)->where('numero', $numCompostage)->first();

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

                $candidat = Candidat::where('epreuve_id', $epreuve->id)
                    ->where('etudiant_id', $etudiantId)
                    ->first();

                if ($candidat === null) {
                    $erreurs[$index] = 'Étudiant non convoqué à cette épreuve.';

                    continue;
                }

                if ($candidat->estAbsent() && ($ligne['valeur'] ?? null) !== null) {
                    $erreurs[$index] = 'Candidat absent : aucune note ne peut être saisie.';

                    continue;
                }

                $valeur = $ligne['valeur'] ?? null;

                if ($valeur !== null && ($valeur < 0 || $valeur > (float) $epreuve->note_max)) {
                    $erreurs[$index] = "Note hors bornes 0..{$epreuve->note_max}.";

                    continue;
                }

                /** @var NoteEpreuve $note */
                $note = NoteEpreuve::firstOrNew([
                    'epreuve_id' => $epreuve->id,
                    'etudiant_id' => $candidat->etudiant_id,
                ]);

                if ($note->exists && $note->estVerrouillee()) {
                    $ignorees++;

                    continue;
                }

                $ancienne = $note->exists ? $note->valeur : null;
                $estNouvelle = ! $note->exists;

                $note->fill([
                    'candidat_id' => $candidat->id,
                    'valeur' => $valeur,
                    'note_max' => $epreuve->note_max,
                    'coefficient' => $epreuve->coefficient,
                    'statut' => $note->exists && $note->statut === 'validated' ? 'validated' : 'entered',
                    'commentaire' => $ligne['commentaire'] ?? $note->commentaire,
                    'saisi_par' => $auteurId,
                ]);
                $note->save();

                if ((string) $ancienne !== (string) $note->valeur) {
                    NoteEpreuveHistorique::create([
                        'evaluation_note_id' => $note->id,
                        'ancienne_valeur' => $ancienne,
                        'nouvelle_valeur' => $note->valeur,
                        'motif' => $ligne['motif'] ?? 'Saisie',
                        'auteur_id' => $auteurId,
                        'created_at' => now(),
                    ]);
                }

                Log::info($estNouvelle ? 'note.entered' : 'note.updated', [
                    'epreuve_id' => $epreuve->id,
                    'note_id' => $note->id,
                    'auteur_id' => $auteurId,
                ]);

                $saisies++;
            }

            EvaluationChanged::dispatch('notes.saisies', $epreuve->id);

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
            $note->loadMissing(['epreuve.matiere', 'etudiant', 'candidat']);

            if ($note->estVerrouillee()) {
                throw new DomainException('Note verrouillée.');
            }

            if ($note->valeur === null) {
                throw new DomainException('Aucune valeur à valider.');
            }

            if (! $note->peutTransiterVers('validated')) {
                throw new DomainException("Validation interdite depuis « {$note->statut} ».");
            }

            if ($note->candidat !== null && $note->candidat->estAbsent()) {
                throw new DomainException('Candidat absent : validation interdite.');
            }

            $epreuve = $note->epreuve;
            $etudiant = $note->etudiant;

            $inscription = $this->inscriptionPourEpreuve($etudiant, $epreuve);
            $semestre = $this->semestrePourMatiere($inscription, $epreuve->matiere->semestreRelatif());

            $sessionOfficielle = $this->sessionOfficielle($epreuve);

            $officielle = Note::where('semestre_id', $semestre->id)
                ->where('matiere_id', $epreuve->matiere_id)
                ->where('session', $sessionOfficielle)
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
                    'note' => (float) $note->valeur,
                    'session' => $sessionOfficielle,
                    'observation' => $motif,
                ]);
            } else {
                $this->archives->modifierNote($officielle, [
                    'note' => (float) $note->valeur,
                    'raison' => $motif ?? 'Validation épreuve',
                ]);
            }

            $note->update([
                'note_id' => $officielle->id,
                'statut' => 'validated',
                'valide_par' => $validateurId,
            ]);

            EvaluationChanged::dispatch('note.validee', $epreuve->id);

            return $note->fresh();
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

        return $inscription->semestres()->where('numero', $numero)->firstOrFail();
    }

    private function sessionOfficielle(Epreuve $epreuve): string
    {
        $epreuve->loadMissing('session');

        return $epreuve->session !== null && $epreuve->session->type === 'rattrapage'
            ? 'rattrapage'
            : 'normale';
    }
}
