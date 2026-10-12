<?php

namespace App\Modules\Evaluation\Jobs;

use App\Models\User;
use App\Modules\Evaluation\Exceptions\RunAnnuleException;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\SurveillanceAssignmentRun;
use App\Modules\Evaluation\Services\ContraintesSurveillance;
use App\Modules\Evaluation\Services\SurveillanceConstraintResolver;
use App\Modules\Evaluation\Services\SurveillanceEquilibrageService;
use App\Services\NotificationService;
use DomainException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job asynchrone d'affectation automatique et d'optimisation des surveillants aux examens.
 *
 * Traitement en arrière-plan avec suivi de progression fin, respect strict des contraintes
 * dures et optimisation des contraintes souples via le moteur d'équilibrage existant.
 */
class AffectationSurveillanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(
        public readonly int $sessionId,
        public readonly ?int $faculteId,
        public readonly int $userId,
        public readonly int $runId,
        public readonly string $mode = 'auto',
    ) {
        $this->onQueue((string) config('affectations.surveillance_runs.file', 'default'));
    }

    /**
     * Verrouillage anti-chevauchement : un seul traitement par session et faculté.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $cle = 'surveillance_assign:'.$this->sessionId.':'.($this->faculteId ?? 'all');

        return [
            (new WithoutOverlapping($cle))->expireAfter(3600),
        ];
    }

    public function handle(
        SurveillanceEquilibrageService $equilibrage,
        NotificationService $notifications,
        SurveillanceConstraintResolver $resolver
    ): void {
        $run = SurveillanceAssignmentRun::find($this->runId);

        if ($run === null || $run->status === SurveillanceAssignmentRun::STATUS_CANCELLED) {
            return;
        }

        if ($this->mode !== 'auto') {
            $run->update([
                'status' => SurveillanceAssignmentRun::STATUS_FAILED,
                'finished_at' => now(),
                'progress' => 100,
                'error_message' => "Mode de traitement inconnu : {$this->mode} (seul « auto » est dispatché).",
            ]);

            return;
        }

        // Transition atomique PENDING -> RUNNING (Garde de concurrence)
        $pris = SurveillanceAssignmentRun::whereKey($this->runId)
            ->where('status', SurveillanceAssignmentRun::STATUS_PENDING)
            ->update([
                'status' => SurveillanceAssignmentRun::STATUS_RUNNING,
                'started_at' => now(),
                'progress' => 5,
            ]);

        if ($pris === 0 && $run->status !== SurveillanceAssignmentRun::STATUS_RUNNING) {
            return;
        }

        $run->refresh();

        $session = SessionExamen::find($this->sessionId);
        $user = User::find($this->userId);

        // L'auteur est re-vérifié à l'exécution (permission du bouton
        // d'origine) : jamais d'écriture si le droit a été retiré.
        $permissionRequise = 'surveillances.assign';
        if ($user === null || ! $user->can($permissionRequise)) {
            $run->update([
                'status' => SurveillanceAssignmentRun::STATUS_FAILED,
                'finished_at' => now(),
                'progress' => 100,
                'error_message' => 'Auteur introuvable ou permission révoquée : traitement refusé.',
            ]);

            return;
        }

        if ($session === null) {
            $run->update([
                'status' => SurveillanceAssignmentRun::STATUS_FAILED,
                'finished_at' => now(),
                'progress' => 100,
                'error_message' => "La session d'examen #{$this->sessionId} est introuvable.",
            ]);

            return;
        }

        // Snapshot unique : la configuration applicable au démarrage fige
        // toute l'exécution (reproductibilité + audit + déterminisme).
        $contraintes = ! empty($run->config_snapshot)
            ? ContraintesSurveillance::depuisParams($run->config_snapshot)
            : $resolver->resoudrePourSession($session, $this->faculteId);

        if (empty($run->config_snapshot)) {
            $run->update(['config_snapshot' => array_merge(
                $contraintes->versParams(),
                ['figee_le' => now()->toIso8601String()]
            )]);
        }

        // Vérification d'absence d'autre traitement RUNNING concurrent
        $autreEnCours = SurveillanceAssignmentRun::where('session_examen_id', $this->sessionId)
            ->where('id', '!=', $this->runId)
            ->when($this->faculteId !== null, fn ($q) => $q->where('faculte_id', $this->faculteId), fn ($q) => $q->whereNull('faculte_id'))
            ->where('status', SurveillanceAssignmentRun::STATUS_RUNNING)
            ->exists();

        if ($autreEnCours) {
            $run->update([
                'status' => SurveillanceAssignmentRun::STATUS_FAILED,
                'finished_at' => now(),
                'progress' => 100,
                'error_message' => "Une autre affectation est déjà en cours d'exécution pour cette session.",
            ]);

            return;
        }

        $totalEpreuves = Epreuve::where('session_id', $this->sessionId)
            ->where('statut', '!=', 'cancelled')
            ->when($this->faculteId !== null, fn ($q) => $q->pourFaculteId($this->faculteId))
            ->count();

        $run->update([
            'total_epreuves' => $totalEpreuves,
            'progress' => 10,
        ]);

        try {
            $this->executerAffectationAuto($session, $equilibrage, $run, $user, $notifications, $totalEpreuves, $contraintes);
        } catch (RunAnnuleException) {
            $run->update([
                'status' => SurveillanceAssignmentRun::STATUS_CANCELLED,
                'finished_at' => now(),
            ]);

            return;
        } catch (DomainException $e) {
            $run->update([
                'status' => SurveillanceAssignmentRun::STATUS_FAILED,
                'finished_at' => now(),
                'progress' => 100,
                'error_message' => $e->getMessage(),
                'erreurs' => $run->erreurs + 1,
            ]);

            if ($user !== null) {
                $notifications->notifierUser(
                    $user,
                    'surveillance.affectation_erreur',
                    '✕ Échec de l’affectation',
                    $e->getMessage(),
                    route('admin.evaluations.affectations.runs', ['run' => $run->uuid]),
                    ['run_id' => $run->id, 'session_id' => $session->id],
                    2
                );
            }

            Log::warning('affectation.job_domain_exception', [
                'run_id' => $run->id,
                'session_id' => $session->id,
                'message' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            report($e);

            $run->update([
                'status' => SurveillanceAssignmentRun::STATUS_FAILED,
                'finished_at' => now(),
                'progress' => 100,
                'error_message' => "Une erreur critique est survenue : {$e->getMessage()}",
                'erreurs' => $run->erreurs + 1,
            ]);

            if ($user !== null) {
                $notifications->notifierUser(
                    $user,
                    'surveillance.affectation_erreur',
                    '✕ Erreur critique d’affectation',
                    $e->getMessage(),
                    route('admin.evaluations.affectations.runs', ['run' => $run->uuid]),
                    ['run_id' => $run->id, 'session_id' => $session->id],
                    3
                );
            }

            throw $e;
        }
    }

    private function executerAffectationAuto(
        SessionExamen $session,
        SurveillanceEquilibrageService $equilibrage,
        SurveillanceAssignmentRun $run,
        ?User $user,
        NotificationService $notifications,
        int $totalEpreuves,
        ContraintesSurveillance $contraintes
    ): void {
        // Phase 1 : Simulation avec rapport de progression
        $optionsSimulation = [
            'faculte_id' => $this->faculteId,
            'contraintes' => $contraintes,
            'progression_callback' => function (int $index, int $total, $epreuve) use ($run) {
                $p = 10 + (int) round(($index / max(1, $total)) * 40);
                $run->update([
                    'progress' => min(50, $p),
                    'epreuves_traitees' => $index,
                ]);
            },
        ];

        $simulation = $equilibrage->simulerSession($session, $optionsSimulation);

        // Si le plan est complètement IMPOSSIBLE (contraintes bloquantes insurmontables)
        if (($simulation['statut'] ?? '') === 'IMPOSSIBLE') {
            $msg = $simulation['message_statut'] ?? 'Application impossible : contraintes bloquantes détectées.';
            $resume = $this->formaterResume($session, $simulation, 0, 0, SurveillanceAssignmentRun::STATUS_FAILED, $contraintes ?? null);

            $run->update([
                'status' => SurveillanceAssignmentRun::STATUS_FAILED,
                'finished_at' => now(),
                'progress' => 100,
                'conflits' => (int) ($simulation['conflits_detectes'] ?? 0),
                'erreurs' => count($simulation['anomalies'] ?? []),
                'error_message' => $msg,
                'result_summary' => $resume,
            ]);

            if ($user !== null) {
                $notifications->notifierUser(
                    $user,
                    'surveillance.affectation_impossible',
                    '✕ Affectation impossible',
                    $msg,
                    route('admin.evaluations.affectations.runs', ['run' => $run->uuid]),
                    ['run_id' => $run->id, 'session_id' => $session->id],
                    2
                );
            }

            return;
        }

        // Phase 2 : application par épreuve (une transaction chacune) —
        // une épreuve en échec métier devient un avertissement documenté,
        // jamais un rollback global (stratégie batch, cf. service).
        $run->update(['progress' => 55]);

        $callbackApplication = function (int $epreuvesTraitees, int $totalAssignes, int $epreuvesIgnorees) use ($run, $totalEpreuves) {
            if (SurveillanceAssignmentRun::whereKey($run->id)->value('status') === SurveillanceAssignmentRun::STATUS_CANCELLED) {
                throw new RunAnnuleException;
            }

            $p = 55 + (int) round(($epreuvesTraitees / max(1, $totalEpreuves)) * 40);
            $run->update([
                'progress' => min(95, $p),
                'epreuves_traitees' => $epreuvesTraitees,
                'affectations_creees' => $totalAssignes,
            ]);
        };

        $resultat = $equilibrage->appliquerSessionParLots($session, $simulation, $this->userId, $callbackApplication);

        $avertissements = $resultat['avertissements'] ?? [];
        $degradations = $simulation['contraintes_souples_degradees'] ?? [];
        // REALISABLE avec dégradations = REALISABLE_AVEC_DEGRADATION :
        // le run l'expose, sans bloquer (contraintes dures respectées).
        $statutFinal = ($avertissements !== [] || $degradations !== [])
            ? SurveillanceAssignmentRun::STATUS_COMPLETED_WITH_WARNINGS
            : SurveillanceAssignmentRun::STATUS_COMPLETED;

        $epreuvesMaj = $resultat['epreuves_traitees'] ?? 0;
        $totalAssignes = $resultat['assignes'] ?? 0;
        $resume = $this->formaterResume($session, $simulation, $epreuvesMaj, $totalAssignes, $statutFinal, $contraintes ?? null);
        $resume['epreuves_ignorees'] = $resultat['epreuves_ignorees'] ?? 0;
        $resume['avertissements'] = $avertissements;
        $resume['degradations_souples'] = $degradations;

        $run->update([
            'status' => $statutFinal,
            'finished_at' => now(),
            'progress' => 100,
            'epreuves_traitees' => $epreuvesMaj,
            'affectations_creees' => $totalAssignes,
            'conflits' => (int) ($simulation['conflits_detectes'] ?? 0),
            'erreurs' => count($avertissements),
            'error_message' => null,
            'result_summary' => $resume,
        ]);

        if ($user !== null) {
            $titre = ($statutFinal === SurveillanceAssignmentRun::STATUS_COMPLETED_WITH_WARNINGS)
                ? '⚠ Affectation terminée avec avertissements'
                : '✓ Affectation automatique terminée';

            $msg = "Session « {$session->nom} » : {$epreuvesMaj} épreuves traitées, {$totalAssignes} surveillances attribuées."
                .($avertissements !== [] ? ' '.count($avertissements).' épreuve(s) en avertissement.' : '');

            $notifications->notifierUser(
                $user,
                'surveillance.affectation_terminee',
                $titre,
                $msg,
                route('admin.evaluations.affectations.runs', ['run' => $run->uuid]),
                ['run_id' => $run->id, 'session_id' => $session->id]
            );
        }
    }

    public function failed(\Throwable $e): void
    {
        SurveillanceAssignmentRun::whereKey($this->runId)->update([
            'status' => SurveillanceAssignmentRun::STATUS_FAILED,
            'finished_at' => now(),
            'progress' => 100,
            'error_message' => mb_substr('Échec technique : '.$e->getMessage(), 0, 2000),
        ]);
    }

    /**
     * @param  array<string, mixed>  $simulation
     * @return array<string, mixed>
     */
    private function formaterResume(
        SessionExamen $session,
        array $simulation,
        int $epreuvesAffectees,
        int $totalAssignes,
        string $statutFinal,
        ?ContraintesSurveillance $contraintes = null
    ): array {
        // Clés réelles de simulerSession (bilan_enseignants : charge, jours).
        $bilan = $simulation['bilan_enseignants'] ?? [];
        $mobilises = array_filter($bilan, fn ($e) => ($e['charge'] ?? 0.0) > 0);
        $nbMobilises = count($mobilises);

        $joursTotaux = 0;
        $plus3Jours = 0;
        foreach ($mobilises as $ens) {
            $nbJours = (int) ($ens['jours'] ?? 0);
            $joursTotaux += $nbJours;
            if ($nbJours > SurveillanceEquilibrageService::CIBLE_JOURS_PRESENCE) {
                $plus3Jours++;
            }
        }

        $joursMoyens = $nbMobilises > 0 ? round($joursTotaux / $nbMobilises, 1) : 0.0;
        $indicateurs = $simulation['indicateurs_charge'] ?? ['min' => 0, 'max' => 0, 'ecart' => 0, 'moyenne' => 0];

        $totalEpreuves = (int) ($simulation['total_epreuves'] ?? 0);
        $impossibles = ($statutFinal === SurveillanceAssignmentRun::STATUS_FAILED) ? $totalEpreuves : 0;
        $avecAvertissements = ($statutFinal === SurveillanceAssignmentRun::STATUS_COMPLETED_WITH_WARNINGS) ? count($simulation['anomalies'] ?? []) : 0;

        return [
            'session_nom' => $session->nom,
            'statut_final' => $statutFinal,
            'epreuves_analysees' => $totalEpreuves,
            'epreuves_affectees' => $epreuvesAffectees,
            'epreuves_avec_avertissements' => $avecAvertissements,
            'epreuves_impossibles' => $impossibles,
            'enseignants_mobilises' => $nbMobilises,
            'surveillances' => (int) ($simulation['total_surveillants_actifs'] ?? 0),
            'gardes' => (int) ($simulation['total_gardes_reserve'] ?? 0),
            'charge_min' => $indicateurs['min'] ?? 0,
            'charge_max' => $indicateurs['max'] ?? 0,
            'charge_moyenne' => $indicateurs['moyenne'] ?? 0,
            'ecart' => $indicateurs['ecart'] ?? 0,
            'jours_moyens' => $joursMoyens,
            'enseignants_plus_3_jours' => $plus3Jours,
            'conflits' => (int) ($simulation['conflits_detectes'] ?? 0),
            'anomalies' => $simulation['anomalies'] ?? [],
            'charge_enseignement' => $this->resumerChargeEnseignement($bilan, $contraintes),
        ];
    }

    /**
     * Synthèse du critère charge d'enseignement (§6) : heures moyennes
     * mesurées, part sans données, activation — jamais présentée comme
     * une vérité réglementaire (heures attribuées, pas effectuées).
     *
     * @param  array<int, array<string, mixed>>  $bilan
     * @return array<string, mixed>
     */
    private function resumerChargeEnseignement(array $bilan, ?ContraintesSurveillance $contraintes): array
    {
        $heures = array_map(fn ($e) => (float) ($e['heures_enseignement'] ?? 0.0), $bilan);
        $mesurees = array_filter($heures, fn ($h) => $h > 0);

        return [
            'actif' => $contraintes?->actifChargeEnseignement ?? false,
            'poids' => $contraintes?->poidsChargeEnseignementEffectif() ?? 0.0,
            'heures_moyennes' => $heures !== [] ? round(array_sum($heures) / count($heures), 1) : 0.0,
            'enseignants_sans_donnees' => count($heures) - count($mesurees),
            'donnees_completes' => $bilan !== [] && ($bilan[0]['donnees_charge_completes'] ?? true),
        ];
    }
}
