<?php

namespace App\Services;

use App\Events\InscriptionValidee;
use App\Events\PopulationChanged;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\InscriptionPedagogique;
use App\Models\Matiere;
use App\Models\RegistrationPeriod;
use App\Models\SituationEtudiant;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\MaquetteUeEcue;
use App\Modules\Referentiel\Models\Niveau;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Inscriptions administratives + pédagogiques (§19-26, §53) : workflow
 * contrôlé, réinscription historisée, pédagogique adossée à la maquette
 * ACTIVE (jamais d'UE arbitraire). Tout est transactionnel.
 */
class InscriptionService
{
    public const TRANSITIONS = [
        'en_cours' => ['valide', 'ajourne', 'abandon'],
        'valide' => [],
        'ajourne' => [],
        'abandon' => [],
    ];

    public function __construct(
        private readonly AuditService $audit,
        private readonly PeriodeInscriptionService $periodes,
    ) {}

    /**
     * @throws \DomainException
     */
    public function transiter(InscriptionAcademique $inscription, string $nouveau, ?int $auteurId, ?string $motif = null): void
    {
        if (! in_array($nouveau, self::TRANSITIONS[$inscription->statut] ?? [], true)) {
            throw new \DomainException("Transition {$inscription->statut} → {$nouveau} interdite.");
        }

        // Finalisation : pièces obligatoires complètes (matrice vide =
        // rien imposé → autorisé, transition legacy préservée).
        if ($nouveau === 'valide') {
            app(DocumentsRequisService::class)->exigerPourValidation(
                $inscription->etudiant, $inscription->type ?? 'premiere'
            );

            // Verdict BLOQUANT du moteur intelligent : explication exigible,
            // jamais de validation aveugle (corriger → re-vérifier → valider).
            $bilan = app(BlocageInscriptionService::class)->blocages($inscription->fresh());

            if ($bilan['bloquant']) {
                $codes = implode(', ', array_unique(array_column(
                    array_filter($bilan['items'], fn (array $i) => $i['gravite'] === 'bloquant'),
                    'code'
                )));

                throw new \DomainException("Validation refusée : blocages critiques non résolus ({$codes}). Corrigez puis re-vérifiez.");
            }
        }

        $ancien = $inscription->statut;

        DB::transaction(function () use ($inscription, $nouveau, $auteurId, $motif, $ancien) {
            /** @var InscriptionAcademique $verrouillee */
            $verrouillee = InscriptionAcademique::whereKey($inscription->id)->lockForUpdate()->firstOrFail();

            if (! in_array($nouveau, self::TRANSITIONS[$verrouillee->statut] ?? [], true)) {
                throw new \DomainException("Transition {$verrouillee->statut} → {$nouveau} interdite (concurrence).");
            }

            $verrouillee->update(['statut' => $nouveau]);

            SituationEtudiant::create([
                'etudiant_id' => $verrouillee->etudiant_id,
                'type' => 'note',
                'ancienne_valeur' => "inscription:{$ancien}",
                'nouvelle_valeur' => "inscription:{$nouveau}",
                'motif' => $motif,
                'auteur_id' => $auteurId,
                'date_effet' => now()->toDateString(),
            ]);

            $this->audit->tracer(
                $verrouillee->etudiant,
                HistoriqueAcademique::ACTION_MODIFICATION_INSCRIPTION,
                'inscription',
                $verrouillee->id,
                ['statut' => $ancien],
                ['statut' => $nouveau, 'motif' => $motif],
            );
        });

        // Phase 15 §15.4 : envoi via le centre (gabarit versionné,
        // préférences, déduplication) — texte contractuel inchangé.
        app(CommunicationDispatcher::class)->dispatcher(
            "inscription.{$nouveau}",
            $inscription->etudiant,
            ['annee' => $inscription->anneeUniversitaire?->libelle, 'statut' => $nouveau],
            route('etudiant.dashboard'),
        );

        PopulationChanged::dispatch('inscriptions', $nouveau, $inscription->id, $auteurId);

        // LOOP affectation automatique : APRÈS commit, inscription
        // conservée même si l'affectation échoue (le listener audite).
        if ($nouveau === 'valide') {
            InscriptionValidee::dispatch(
                $inscription->id,
                (int) $inscription->etudiant_id,
                (int) $inscription->annee_universitaire_id,
                $auteurId,
            );
        }
    }

    /**
     * Réinscription (§25) : nouvelle inscription, historique conservé,
     * niveau progressé si la chaîne LMD le permet.
     *
     * @throws \DomainException
     */
    public function reinscrire(
        Etudiant $etudiant,
        AnneeUniversitaire $annee,
        ?int $auteurId,
        array $donnees = [],
        bool $notifier = true,
    ): InscriptionAcademique {
        if ($etudiant->estSuspendu()) {
            throw new \DomainException('Étudiant suspendu : réinscription impossible.');
        }

        // LOOP périodes : garde SERVEUR avant toute écriture.
        $this->periodes->verifierCreation($etudiant, $annee, 'reinscription');

        $exceptionId = null;

        $inscription = DB::transaction(function () use ($etudiant, &$exceptionId, $annee, $auteurId, $donnees, $notifier) {
            // Re-vérification verrouillée DANS la transaction (§19-20).
            RegistrationPeriod::where('annee_universitaire_id', $annee->id)->lockForUpdate()->get();
            PeriodeInscriptionService::oublierMemo();
            $exceptionId = $this->periodes->verifierCreation($etudiant, $annee, 'reinscription');
            // Garde interne (le contrôleur la vérifie aussi) : idempotence
            // même en appel direct du service. Verrou pessimiste sur
            // l'étudiant + contrainte unique = double clic/retry sans doublon.
            Etudiant::whereKey($etudiant->id)->lockForUpdate()->firstOrFail();

            if ($etudiant->inscriptionsAcademiques()->where('annee_universitaire_id', $annee->id)->exists()) {
                throw new \DomainException('Une inscription existe déjà pour cette année (historique conservé).');
            }

            $precedente = $etudiant->inscriptionsAcademiques()
                ->where('annee_universitaire_id', '!=', $annee->id)
                ->orderByDesc('annee_universitaire_id')->first();

            $niveauId = $donnees['niveau_id'] ?? $this->niveauSuivant($precedente);

            // Cohérence niveau_id ↔ niveau (code) : quand un niveau est
            // visé explicitement, le code vient du référentiel officiel —
            // jamais de l'ancienne inscription (sinon L2 avec libellé L1).
            $codeNiveau = $donnees['niveau']
                ?? ($niveauId !== null && (int) $niveauId !== (int) ($precedente?->niveau_id ?? 0)
                    ? Niveau::find($niveauId)?->code
                    : null)
                ?? $precedente?->niveau
                ?? $etudiant->niveau;

            try {
                $inscription = InscriptionAcademique::create([
                    'etudiant_id' => $etudiant->id,
                    'annee_universitaire_id' => $annee->id,
                    'programme_id' => $donnees['programme_id'] ?? $precedente?->programme_id ?? $etudiant->programme_id,
                    'niveau_id' => $niveauId,
                    'niveau' => $codeNiveau,
                    'filiere' => $donnees['filiere'] ?? $precedente?->filiere,
                    'statut' => 'en_cours',
                    'type' => 'reinscription',
                    'regime' => $donnees['regime'] ?? 'initiale',
                    // Phase 6 §30 : cause explicite (ex. redoublement sur
                    // décision jury) — le type reste `reinscription`
                    // (contrainte enum SQLite/MySQL partagée).
                    'observation' => $donnees['observation'] ?? null,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // Course concurrence (double clic) : 422 lisible, pas de 500.
                throw new \DomainException('Une inscription existe déjà pour cette année (création concurrente ignorée).');
            }

            foreach ([1, 2] as $numero) {
                $inscription->semestres()->create(['numero' => $numero]);
            }

            SituationEtudiant::create([
                'etudiant_id' => $etudiant->id,
                'type' => 'reinscription',
                'nouvelle_valeur' => $annee->libelle,
                'auteur_id' => $auteurId,
                'date_effet' => now()->toDateString(),
            ]);

            $this->audit->tracer(
                $etudiant,
                HistoriqueAcademique::ACTION_CREATION_INSCRIPTION,
                'inscription',
                $inscription->id,
                null,
                ['annee' => $annee->libelle, 'type' => 'reinscription', 'periode_exception_id' => $exceptionId],
            );

            if ($notifier) {
                // Phase 15 §15.4 : centre (texte contractuel inchangé).
                app(CommunicationDispatcher::class)->dispatcher(
                    'inscription.reinscrite',
                    $etudiant,
                    ['annee' => $annee->libelle],
                    route('etudiant.dashboard'),
                );
            }

            PopulationChanged::dispatch('inscriptions', 'reinscription', $inscription->id, $auteurId);

            return $inscription;
        });

        $this->periodes->marquerUtilisee($exceptionId);

        return $inscription;
    }

    /**
     * Inscription pédagogique à une ECUE (§24) : UE dans la maquette
     * ACTIVE du programme, ECUE rattachée à l'UE.
     *
     * @throws \DomainException
     */
    public function inscrireEcue(
        InscriptionAcademique $inscription,
        Matiere $matiere,
        ?int $auteurId,
    ): InscriptionPedagogique {
        $inscription->loadMissing(['etudiant', 'anneeUniversitaire']);

        if ($matiere->ue_id === null) {
            throw new \DomainException("ECUE {$matiere->code} non rattachée à une UE.");
        }

        $ue = $matiere->ue;

        $maquette = Maquette::where('programme_id', $inscription->programme_id ?? $inscription->etudiant->programme_id)
            ->where('annee_universitaire_id', $inscription->annee_universitaire_id)
            ->where('statut', 'active')
            ->first();

        if ($maquette === null) {
            throw new \DomainException('Aucune maquette active pour ce programme et cette année.');
        }

        if (! $maquette->unitesEnseignement()->where('unites_enseignement.id', $ue->id)->exists()) {
            throw new \DomainException("UE {$ue->code} hors maquette active.");
        }

        // Vague 3 (audit 08/10/2026) : si le plan actif définit
        // EXPLICITEMENT les ECUE de cette UE (maquette_ue_ecue),
        // l'inscription doit viser une ECUE du plan — sinon repli sur
        // le rattachement UE (matieres.ue_id, FALLBACK documenté).
        $idsPlan = MaquetteUeEcue::whereHas('maquetteUe', fn ($q) => $q
            ->where('maquette_id', $maquette->id)
            ->where('ue_id', $ue->id))
            ->pluck('matiere_id');

        if ($idsPlan->isNotEmpty() && ! $idsPlan->contains($matiere->id)) {
            throw new \DomainException("ECUE {$matiere->code} hors du plan d'études actif.");
        }

        // §17 K_PARMI_N (GO condition 2, audit 08/10/2026) : une ECUE
        // d'un groupe de choix ne peut dépasser le maximum du groupe
        // (bornes dans `conditions` : clés `min_choix`/`max_choix` —
        // configurables, zéro règle en dur). Le minimum est signalé en
        // masse (choix partiel légitime pendant la saisie).
        $occurrence = MaquetteUeEcue::whereHas('maquetteUe', fn ($q) => $q
            ->where('maquette_id', $maquette->id)
            ->where('ue_id', $ue->id))
            ->where('matiere_id', $matiere->id)
            ->first();

        if ($occurrence?->groupe_choix !== null) {
            $bornes = $this->bornesGroupeChoix($maquette, $occurrence->groupe_choix);

            if ($bornes['max'] !== null) {
                $dejaChoisies = InscriptionPedagogique::where('inscription_id', $inscription->id)
                    ->where('statut', 'inscrit')
                    ->whereIn('matiere_id', $bornes['matieres'])
                    ->where('matiere_id', '!=', $matiere->id)
                    ->count();

                if ($dejaChoisies + 1 > $bornes['max']) {
                    throw new \DomainException(
                        "Groupe de choix « {$occurrence->groupe_choix} » : {$bornes['max']} matière(s) maximum autorisée(s)."
                    );
                }
            }
        }

        return DB::transaction(function () use ($inscription, $matiere, $ue, $auteurId) {
            $pedago = InscriptionPedagogique::firstOrCreate(
                ['inscription_id' => $inscription->id, 'matiere_id' => $matiere->id],
                ['semestre_id' => $ue->semestre_id, 'ue_id' => $ue->id, 'statut' => 'inscrit']
            );

            PopulationChanged::dispatch('inscriptions-pedagogiques', 'inscription',
                $pedago->id, $auteurId, ['matiere' => $matiere->code]);

            return $pedago;
        });
    }

    /**
     * Inscriptions pédagogiques en masse (Phase 4 §12) : mêmes gardes
     * que l'unitaire (maquette ACTIVE, idempotence), succès partiel
     * avec rapport (créés + déjà inscrits + erreurs), jamais de
     * rollback global sur une ligne fautive — comme les imports.
     *
     * @param  array<int, int>  $matiereIds
     * @return array{crees: int, existants: int, erreurs: array<int, string>}
     */
    public function inscrireEcues(
        InscriptionAcademique $inscription,
        array $matiereIds,
        ?int $auteurId,
    ): array {
        $rapport = ['crees' => 0, 'existants' => 0, 'erreurs' => []];

        foreach (array_unique(array_map('intval', $matiereIds)) as $matiereId) {
            if ($matiereId <= 0) {
                continue;
            }

            $matiere = Matiere::find($matiereId);

            if ($matiere === null) {
                $rapport['erreurs'][$matiereId] = "ECUE #{$matiereId} introuvable.";

                continue;
            }

            $existait = InscriptionPedagogique::where('inscription_id', $inscription->id)
                ->where('matiere_id', $matiere->id)->exists();

            try {
                $this->inscrireEcue($inscription, $matiere, $auteurId);
            } catch (\DomainException $e) {
                $rapport['erreurs'][$matiereId] = $e->getMessage();

                continue;
            }

            if ($existait) {
                $rapport['existants']++;
            } else {
                $rapport['crees']++;
            }
        }

        // §17 : groupes de choix incomplets (min_choix) — informatif,
        // jamais bloquant (le choix peut être complété plus tard).
        $rapport['avertissements'] = $this->avertissementsGroupesIncomplets($inscription);

        return $rapport;
    }

    /**
     * Bornes K_PARMI_N d'un groupe de choix du plan actif (convention
     * documentée : `conditions` JSON porte `min_choix`/`max_choix` —
     * aucune règle codée en dur, aucune table supplémentaire).
     *
     * @return array{min: ?int, max: ?int, matieres: Collection<int, int>}
     */
    private function bornesGroupeChoix(Maquette $maquette, string $groupe): array
    {
        $occurrences = MaquetteUeEcue::whereHas('maquetteUe', fn ($q) => $q->where('maquette_id', $maquette->id))
            ->where('groupe_choix', $groupe)
            ->get();

        return [
            'min' => optional($occurrences->pluck('conditions')->filter()->pluck('min_choix')->filter()->min(), fn ($v) => (int) $v),
            'max' => optional($occurrences->pluck('conditions')->filter()->pluck('max_choix')->filter()->min(), fn ($v) => (int) $v),
            'matieres' => $occurrences->pluck('matiere_id'),
        ];
    }

    /**
     * Groupes de choix ENTAMÉS mais sous le minimum requis — signalés
     * en masse (un groupe jamais entamé est un choix à venir, pas un
     * avertissement).
     *
     * @return array<int, string>
     */
    private function avertissementsGroupesIncomplets(InscriptionAcademique $inscription): array
    {
        $maquette = Maquette::where('programme_id', $inscription->programme_id ?? $inscription->etudiant?->programme_id)
            ->where('annee_universitaire_id', $inscription->annee_universitaire_id)
            ->where('statut', 'active')
            ->first();

        if ($maquette === null) {
            return [];
        }

        $inscrits = InscriptionPedagogique::where('inscription_id', $inscription->id)
            ->where('statut', 'inscrit')
            ->pluck('matiere_id');

        $avertissements = [];

        foreach (MaquetteUeEcue::whereHas('maquetteUe', fn ($q) => $q->where('maquette_id', $maquette->id))
            ->whereNotNull('groupe_choix')
            ->get()
            ->groupBy('groupe_choix') as $groupe => $occurrences) {
            $min = $occurrences->pluck('conditions')->filter()->pluck('min_choix')->filter()->min();

            if ($min === null) {
                continue;
            }

            $choisies = $occurrences->pluck('matiere_id')->intersect($inscrits);

            if ($choisies->isNotEmpty() && $choisies->count() < (int) $min) {
                $avertissements[] = "Groupe de choix « {$groupe} » : {$min} matière(s) requise(s), {$choisies->count()} inscrite(s).";
            }
        }

        return $avertissements;
    }

    /**
     * Niveau suivant dans le même programme (chaîne LMD par `ordre`).
     * Dernier niveau → lui-même (pas de saut de programme deviné).
     */
    public function niveauApres(?int $niveauId): ?Niveau
    {
        if ($niveauId === null) {
            return null;
        }

        $niveau = Niveau::find($niveauId);

        if ($niveau === null) {
            return null;
        }

        return Niveau::where('programme_id', $niveau->programme_id)
            ->where('ordre', '>', $niveau->ordre)
            ->orderBy('ordre')->first() ?? $niveau;
    }

    private function niveauSuivant(?InscriptionAcademique $precedente): ?int
    {
        return $this->niveauApres($precedente?->niveau_id)?->id;
    }

    /**
     * Régularise en masse les inscriptions académiques manquantes pour une année universitaire.
     * Cible les étudiants au statut valide qui n'ont pas encore d'inscription pour cette année.
     *
     * @return array{total_candidats: int, crees: int, erreurs: array<int, string>}
     */
    public function regulariserInscriptionsManquantes(
        int $anneeId,
        ?int $faculteId = null,
        ?int $auteurId = null,
    ): array {
        $annee = AnneeUniversitaire::findOrFail($anneeId);

        $query = Etudiant::query()
            ->where('statut', 'valide')
            ->whereDoesntHave('inscriptionsAcademiques', fn ($q) => $q->where('annee_universitaire_id', $annee->id));

        if ($faculteId !== null) {
            $query->where('faculte_id', $faculteId);
        }

        $candidats = $query->get();
        $totalCandidats = $candidats->count();

        if ($totalCandidats === 0) {
            return [
                'total_candidats' => 0,
                'crees' => 0,
                'erreurs' => [],
            ];
        }

        $crees = 0;
        $erreurs = [];
        $now = now();

        DB::transaction(function () use ($candidats, $annee, $now, &$crees) {
            foreach ($candidats->chunk(500) as $chunk) {
                $inscriptionsLignes = [];
                $etudiantParId = [];

                foreach ($chunk as $etudiant) {
                    $etudiantParId[$etudiant->id] = $etudiant;
                    $niveau = $etudiant->niveau ?: 'L1';

                    $inscriptionsLignes[] = [
                        'etudiant_id' => $etudiant->id,
                        'annee_universitaire_id' => $annee->id,
                        'programme_id' => $etudiant->programme_id,
                        'niveau_id' => $etudiant->niveau_id,
                        'niveau' => $niveau,
                        'filiere' => $etudiant->filiere,
                        'statut' => 'valide',
                        'type' => 'premiere',
                        'regime' => 'initiale',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('inscriptions_academiques')->insert($inscriptionsLignes);

                $createdIds = DB::table('inscriptions_academiques')
                    ->where('annee_universitaire_id', $annee->id)
                    ->whereIn('etudiant_id', array_keys($etudiantParId))
                    ->pluck('id');

                $semestresLignes = [];
                foreach ($createdIds as $inscrId) {
                    $semestresLignes[] = [
                        'inscription_academique_id' => $inscrId,
                        'numero' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $semestresLignes[] = [
                        'inscription_academique_id' => $inscrId,
                        'numero' => 2,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($semestresLignes !== []) {
                    DB::table('semestres')->insert($semestresLignes);
                }

                // Initialisation du dossier académique si absent
                $dossiersExistants = DB::table('archives_etudiants')
                    ->whereIn('etudiant_id', array_keys($etudiantParId))
                    ->pluck('etudiant_id')
                    ->all();

                $dossiersAcreer = [];
                foreach ($etudiantParId as $eid => $etudiant) {
                    if (! in_array($eid, $dossiersExistants, true)) {
                        $matricule = $etudiant->num_inscription ?: ('MAT-'.str_pad((string) $eid, 5, '0', STR_PAD_LEFT));
                        $dossiersAcreer[] = [
                            'etudiant_id' => $eid,
                            'matricule' => $matricule,
                            'date_archivage' => $now->toDateString(),
                            'statut' => 'actif',
                            'observation' => 'Dossier initialisé lors de la régularisation automatique.',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($dossiersAcreer !== []) {
                    DB::table('archives_etudiants')->insert($dossiersAcreer);
                }

                $crees += count($inscriptionsLignes);
            }
        });

        return [
            'total_candidats' => $totalCandidats,
            'crees' => $crees,
            'erreurs' => $erreurs,
        ];
    }
}
