<?php

namespace App\Jobs;

use App\Models\CampagneRedoublement;
use App\Services\Acquis\CampagneRedoublementMasseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Exécution asynchrone d'une campagne de redoublement persistée (constat A4).
 *
 * La requête HTTP ne porte plus l'exécution : elle rend la main avec `202`
 * et une référence suivie par `GET /api/v1/acquis/campagnes/{reference}`.
 * Le job recalcule la cohorte depuis les critères persistés — aucun détail
 * nominatif n'est stocké en base — puis délègue au service qui reste
 * idempotent (reprise après interruption) et verrouille chaque étudiant.
 */
class ExecuterCampagneRedoublementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Aucun retry automatique : une relance manuelle reste tracée par le statut. */
    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public readonly int $campagneId,
    ) {}

    public function handle(CampagneRedoublementMasseService $service): void
    {
        $campagne = CampagneRedoublement::find($this->campagneId);

        // Supprimée entre-temps, ou déjà terminée : ne jamais rejouer.
        if ($campagne === null || $campagne->estTerminee()) {
            return;
        }

        $campagne->update([
            'statut' => CampagneRedoublement::STATUT_EN_COURS,
            'demarree_a' => now(),
        ]);

        try {
            $criteres = array_filter([
                'annee_id' => $campagne->annee_universitaire_id,
                'programme_id' => $campagne->programme_id,
                'groupe_id' => $campagne->groupe_id,
                'faculte_id' => $campagne->faculte_id,
            ], static fn ($valeur) => $valeur !== null);

            $preparation = $service->preparerCampagne($criteres, $campagne->reference);

            $resultat = $service->executerCampagne(
                $preparation,
                (int) ($campagne->lancee_par ?? 1),
                $campagne->ignorer_alertes_mineures
            );

            $campagne->update([
                'statut' => CampagneRedoublement::STATUT_TERMINEE,
                'dossiers_total' => (int) ($preparation['total_etudiants'] ?? 0),
                'dossiers_traites' => (int) ($resultat['etudiants_traites'] ?? 0),
                // Agrégats seulement : `details` (IDs + causes techniques) est écarté.
                'bilan' => [
                    'qualification' => $preparation['qualification'] ?? [],
                    'chiffres_clefs' => $preparation['chiffres_clefs'] ?? [],
                    'execution' => Arr::except($resultat, ['details']),
                ],
                'terminee_a' => now(),
            ]);
        } catch (Throwable $e) {
            // Message volontairement minimal : il peut contenir des valeurs SQL.
            $campagne->update([
                'statut' => CampagneRedoublement::STATUT_ECHEC,
                'erreur' => class_basename($e),
                'terminee_a' => now(),
            ]);

            throw $e;
        }
    }
}
