<?php

namespace App\Modules\Evaluation\Services;

use App\Models\IntegrationLog;
use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\DocumentExamenGenere;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Schedule\Models\Room;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * LOOP-EXAM-PREPARATION-01 — surface de préparation des examens.
 *
 * Comble les lacunes G1-G5 de l'audit sans dupliquer l'existant :
 *  - G1 : agrégats de tableau de bord de préparation ;
 *  - G4 : snapshot de population à la validation (hash + effectif),
 *    comparaison pour signaler les changements ultérieurs ;
 *  - G5 : numéro anonyme stable par candidat et par épreuve
 *    (jamais la CIN ; structure prête pour un futur code-barres).
 *
 * Les règles de population, de conflits et de salles restent celles des
 * services existants (`genererCandidats`, `ConflitEpreuveService`,
 * `ExamRoomAssignmentService`) — aucune règle réinventée.
 */
final class PreparationExamenService
{
    public function __construct(
        private EpreuveService $epreuves,
        private ConflitEpreuveService $conflits,
    ) {}

    /**
     * G1 : agrégats du tableau de bord — requêtes bornées, sans N+1
     * (scope faculté appliqué partout via `pourFaculteId`).
     *
     * @return array{sessions: mixed, total_epreuves: int, par_statut: array<string, int>, sans_salle: int, candidats: int, documents: int}
     */
    public function tableauDeBord(?int $anneeId, ?int $faculteRestreinteId): array
    {
        $scopeEpreuve = fn ($q) => $q
            ->when($anneeId !== null, fn ($r) => $r->where('annee_universitaire_id', $anneeId))
            ->when($faculteRestreinteId !== null, fn ($r) => $r->pourFaculteId($faculteRestreinteId));

        $sessions = SessionExamen::query()
            ->when($anneeId !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->orderByDesc('date_debut')
            ->limit(8)
            ->get();

        $parStatut = Epreuve::query()
            ->when($anneeId !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->when($faculteRestreinteId !== null, fn ($q) => $q->pourFaculteId($faculteRestreinteId))
            ->selectRaw('statut, COUNT(*) as n')
            ->groupBy('statut')
            ->pluck('n', 'statut')
            ->all();

        return [
            'sessions' => $sessions,
            'total_epreuves' => array_sum($parStatut),
            'par_statut' => $parStatut,
            'sans_salle' => Epreuve::query()->when($anneeId !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
                ->when($faculteRestreinteId !== null, fn ($q) => $q->pourFaculteId($faculteRestreinteId))
                ->whereNull('salle_id')->count(),
            'candidats' => Candidat::whereHas('epreuve', $scopeEpreuve)->count(),
            'documents' => DocumentExamenGenere::whereHas('epreuve', $scopeEpreuve)->count(),
        ];
    }

    /**
     * Contrôles de cohérence d'une épreuve (assistant de préparation).
     * Réutilise `ConflitEpreuveService` (horaires, double réservation,
     * capacité au moment de l'affectation) et ajoute les contrôles G4.
     *
     * @return array{bloquants: array<int, string>, avertissements: array<int, string>, effectif: int}
     */
    public function verifierEpreuve(Epreuve $epreuve): array
    {
        $epreuve->loadCount('candidats');

        $bloquants = [];
        $avertissements = [];
        $effectif = (int) $epreuve->candidats_count;

        // 1. Salle (épreuve mono-salle OU répartition multi-salles).
        if ($epreuve->salle_id === null && ! $epreuve->candidats()->whereNotNull('salle_id')->exists()) {
            $bloquants[] = 'Aucune salle affectée.';
        }

        // 2. Conflits — service existant, rien réinventé.
        $listeConflits = $this->conflits->detecter([
            'date' => $epreuve->date->format('Y-m-d'),
            'heure_debut' => substr((string) $epreuve->heure_debut, 0, 5),
            'heure_fin' => substr((string) $epreuve->heure_fin, 0, 5),
            'salle_id' => $epreuve->salle_id,
            'capacite_necessaire' => $epreuve->capacite_necessaire > 0 ? (int) $epreuve->capacite_necessaire : $effectif,
        ], $epreuve->id);

        foreach ($this->conflits->bloquants($listeConflits) as $conflit) {
            $bloquants[] = (string) $conflit['message'];
        }

        // 3. Liste de candidats.
        if ($effectif === 0) {
            $bloquants[] = 'Liste de candidats vide (générez la population).';
        }

        // 4. Capacité par salle — jamais inventée : salle sans capacité
        //    renseignée = avertissement, pas de refus silencieux.
        $candidats = $epreuve->candidats()->with('salle')->get();
        $sallesUtilisees = $candidats->groupBy(fn (Candidat $c) => $c->salle_id ?? $epreuve->salle_id);

        foreach ($sallesUtilisees as $salleId => $groupe) {
            if ($salleId === null) {
                continue;
            }

            /** @var Room|null $salle */
            $salle = $groupe->first()->salle ?? $epreuve->salle()->find($salleId);

            if ($salle === null || (int) $salle->capacite <= 0) {
                $avertissements[] = 'Salle '.($salle?->code ?? $salleId).' : capacité non renseignée — vérification impossible.';

                continue;
            }

            if ($groupe->count() > (int) $salle->capacite) {
                $bloquants[] = 'Salle '.$salle->code.' : '.$groupe->count().' candidat(s) pour une capacité de '.(int) $salle->capacite.'.';
            }
        }

        // 5. Dérive de population depuis la validation (G4).
        $populationModifiee = $this->populationModifiee($epreuve);
        if ($populationModifiee) {
            $avertissements[] = 'Population modifiée depuis la validation du '
                .$epreuve->population_validated_at?->format('d/m/Y H:i')
                .' ('.$epreuve->population_effectif.' → '.$effectif.' candidat(s)).';
        }

        // 6. Contrôle de correspondance stricte Matière ↔ Groupes ↔ Étudiants.
        $correspondance = app(CorrespondanceExamenService::class)->analyserEpreuve($epreuve);
        foreach ($correspondance['bloquants'] as $bloquantCorrespondance) {
            if (! in_array($bloquantCorrespondance, $bloquants, true)) {
                $bloquants[] = $bloquantCorrespondance;
            }
        }
        foreach ($correspondance['avertissements'] as $avertCorrespondance) {
            if (! in_array($avertCorrespondance, $avertissements, true)) {
                $avertissements[] = $avertCorrespondance;
            }
        }

        return [
            'bloquants' => $bloquants,
            'avertissements' => $avertissements,
            'effectif' => $effectif,
            'population_validee' => $epreuve->population_validated_at !== null,
            'population_modifiee' => $populationModifiee,
            'correspondance' => $correspondance,
        ];
    }

    /**
     * G4 + G5 : validation de la liste de candidats d'une épreuve.
     *
     * Transaction + verrous : la population est rafraîchie via les règles
     * existantes (`genererCandidats`, idempotent), les numéros anonymes
     * manquants sont attribués (jamais réattribués), puis le snapshot
     * (hash + effectif) est figé. Une dérive ultérieure est signalée par
     * `populationModifiee` et bloque l'ouverture de session (readiness).
     */
    public function validerPopulation(Epreuve $epreuve, int $auteurId): Epreuve
    {
        return DB::transaction(function () use ($epreuve, $auteurId): Epreuve {
            /** @var Epreuve $verrouillee */
            $verrouillee = Epreuve::whereKey($epreuve->id)->lockForUpdate()->firstOrFail();

            if ($verrouillee->estVerrouillee()) {
                throw new DomainException('Épreuve verrouillée : population non modifiable.');
            }

            // Population courante, règles métier existantes, idempotente.
            $this->epreuves->genererCandidats($verrouillee);

            $analyseCorrespondance = app(CorrespondanceExamenService::class)
                ->analyserEpreuve(
                    $verrouillee->fresh(),
                    exigerEligiblesPourPublication: true,
                    ignorerDeriveSnapshot: true
                );

            if (! $analyseCorrespondance['conforme']) {
                throw new DomainException(
                    CorrespondanceExamenService::ALERTE_PUBLICATION_BLOQUEE.' '.implode(' ', $analyseCorrespondance['bloquants'])
                );
            }

            $candidats = $verrouillee->candidats()->orderBy('etudiant_id')->lockForUpdate()->get();

            // Numéros anonymes : suite des numéros déjà attribués (stabilité).
            $suite = $candidats->filter(fn (Candidat $c) => $c->numero_anonyme !== null)
                ->map(fn (Candidat $c) => (int) filter_var($c->numero_anonyme, FILTER_SANITIZE_NUMBER_INT))
                ->max() ?? 0;

            foreach ($candidats as $candidat) {
                if ($candidat->numero_anonyme === null) {
                    $candidat->forceFill(['numero_anonyme' => sprintf('AN-%04d', ++$suite)])->save();
                }
            }

            $verrouillee->forceFill([
                'population_validated_at' => now(),
                'population_hash' => $this->hashPopulation($verrouillee),
                'population_effectif' => $candidats->count(),
            ])->save();

            EvaluationChanged::dispatch('epreuve.population_validee', $verrouillee->id);

            // Traçabilité sans PII : auteur, épreuve, effectif.
            IntegrationLog::tracer(
                'evaluation', 'exam-preparation', 'population.validee', 'completed',
                (string) json_encode(['epreuve_id' => $verrouillee->id, 'effectif' => $candidats->count(), 'auteur_id' => $auteurId], JSON_UNESCAPED_UNICODE),
                200, null, null, (string) Str::uuid(), 'POP-'.$verrouillee->id,
            );

            return $verrouillee->refresh();
        });
    }

    /**
     * Hash déterministe de la population courante et du périmètre de correspondance
     * (matière, groupe, groupe virtuel, candidats planifiés et éligibles de référence).
     */
    public function hashPopulation(Epreuve $epreuve): string
    {
        $idsCandidats = $epreuve->candidats()->orderBy('etudiant_id')->pluck('etudiant_id')->map(fn ($id) => (int) $id)->all();
        $correspondance = app(CorrespondanceExamenService::class);
        $idsEligibles = $correspondance->identifiantsEligiblesReference($epreuve);
        $gvCle = $correspondance->groupeVirtuelActif($epreuve)?->cle;

        $payload = implode('|', [
            'm:'.($epreuve->matiere_id ?? ''),
            'g:'.($epreuve->groupe_id ?? ''),
            'gv:'.($gvCle ?? ''),
            'c:'.implode(',', $idsCandidats),
            'e:'.implode(',', $idsEligibles),
        ]);

        return hash('sha256', $payload);
    }

    /**
     * G4 : la population a-t-elle dérivé depuis la dernière validation ?
     * Sans snapshot validé : pas de dérive signalable (compatibilité).
     */
    public function populationModifiee(Epreuve $epreuve): bool
    {
        if ($epreuve->population_hash === null) {
            return false;
        }

        return $epreuve->population_hash !== $this->hashPopulation($epreuve);
    }
}
