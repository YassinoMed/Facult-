<?php

namespace App\Jobs;

use App\Models\AffectationMasseLot;
use App\Models\Etudiant;
use App\Models\User;
use App\Services\Affectations\MassAssignmentService;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * LOOP P2 — exécution d'un lot d'affectation par tranches.
 * ORCHESTRATION UNIQUEMENT : progression, reprise, annulation,
 * erreurs. Toute décision (contraintes, capacité, idempotence)
 * reste dans `MassAssignmentService::confirmer()` — le Job ne
 * contient aucune règle métier.
 */
class ExecuterAffectationMasseLot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(public readonly int $lotId)
    {
        $this->onQueue((string) config('affectations.lots.file', 'default'));
    }

    public function handle(
        MassAssignmentService $moteur,
        ?AuditService $audit = null,
        ?NotificationService $notifications = null
    ): void {
        $audit ??= app(AuditService::class);
        $notifications ??= app(NotificationService::class);

        $lot = AffectationMasseLot::findOrFail($this->lotId);

        // Annulation coopérative : un lot annulé ne travaille plus.
        if ($lot->statut === AffectationMasseLot::STATUT_ANNULE) {
            return;
        }

        if ($lot->statut === AffectationMasseLot::STATUT_TERMINE) {
            return;
        }

        // Autorisation réévaluée à l'exécution (§19) : l'auteur doit
        // toujours exister et pouvoir confirmer des affectations.
        $auteur = User::find($lot->auteur_id);
        if ($auteur === null || ! $auteur->hasPermission('archives.update')) {
            $lot->update([
                'statut' => AffectationMasseLot::STATUT_ECHOUE,
                'message' => 'Auteur introuvable ou permission archives.update retirée.',
            ]);

            return;
        }

        $lot->update(['statut' => AffectationMasseLot::STATUT_EN_COURS]);
        $taille = max(100, (int) config('affectations.lots.taille_tranche', 500));

        try {
            $traitesIds = $lot->traites_ids ?? [];
            $restantes = array_values(array_filter(
                $lot->selections ?? [],
                fn ($s) => ! in_array((int) ($s['student_id'] ?? 0), $traitesIds, true)
            ));

            $cumulAffectes = (int) $lot->affectes;
            $cumulIgnores = (int) $lot->ignores;
            $cumulErreurs = $lot->erreurs ?? [];

            foreach (array_chunk($restantes, $taille) as $tranche) {
                // Relecture annulation entre tranches (reprise coopérative).
                if ($lot->fresh()->statut === AffectationMasseLot::STATUT_ANNULE) {
                    return;
                }

                $rapport = $moteur->confirmer($tranche, $lot->annee_universitaire_id, $auteur, (string) ($lot->motif ?? 'Lot'), (bool) $lot->force);

                $traitesIds = array_values(array_unique(array_merge($traitesIds, array_map(
                    fn ($s) => (int) $s['student_id'], $tranche
                ))));
                $cumulAffectes += $rapport['affectes'];
                $cumulIgnores += $rapport['ignores'];
                $cumulErreurs = array_slice(array_merge($cumulErreurs, $rapport['erreurs']), 0, 50, true);

                $lot->update([
                    'traites' => count($traitesIds),
                    'affectes' => $cumulAffectes,
                    'ignores' => $cumulIgnores,
                    'traites_ids' => $traitesIds,
                    'erreurs' => $cumulErreurs,
                ]);

                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }

            $lot->update(['statut' => AffectationMasseLot::STATUT_TERMINE]);

            $premierId = $lot->selections[0]['student_id'] ?? null;
            if ($premierId !== null && ($premier = Etudiant::find($premierId)) !== null) {
                $audit->tracer(
                    $premier,
                    'assignment.mass_lot_completed',
                    'affectation_masse_lot',
                    $lot->id,
                    null,
                    ['reference' => $lot->reference, 'affectes' => $lot->affectes, 'ignores' => $lot->ignores],
                    'systeme'
                );
            }

            $notifications->notifierUser(
                $auteur,
                'affectation.lot_termine',
                'Lot d’affectation terminé',
                "Lot {$lot->reference} : {$lot->affectes} affecté(s), {$lot->ignores} ignoré(s).",
                route('admin.affectations.masse.lots.show', $lot)
            );
        } catch (\Throwable $e) {
            report($e);
            Log::warning('affectation.lot_echec', ['lot_id' => $lot->id]);

            $lot->update([
                'statut' => AffectationMasseLot::STATUT_ECHOUE,
                'message' => mb_substr('Échec technique : '.$e->getMessage(), 0, 500),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        AffectationMasseLot::whereKey($this->lotId)->update([
            'statut' => AffectationMasseLot::STATUT_ECHOUE,
            'message' => mb_substr('Échec technique : '.$e->getMessage(), 0, 500),
        ]);
    }
}
