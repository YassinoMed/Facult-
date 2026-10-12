<?php

namespace App\Listeners;

use App\Events\PeriodeInscriptionOuverte;
use App\Models\Etudiant;
use App\Services\NotificationService;
use Throwable;

/**
 * Notifie les étudiants concernés lors de l'ouverture d'une période d'inscription (LOOP 12) :
 * - Idempotent grâce à la clé de déduplication (periode.ouverte:{periode_id}:{etudiant_id}) ;
 * - Contenu institutionnel sans aucune donnée personnelle ;
 * - Fail-soft.
 */
class NotifierPeriodeOuverte
{
    public function handle(PeriodeInscriptionOuverte $event): void
    {
        try {
            $periode = $event->periode;
            $service = app(NotificationService::class);

            $query = Etudiant::query();
            if ($periode->faculte_id !== null) {
                $query->where('faculte_id', $periode->faculte_id);
            }
            if ($periode->niveau !== null) {
                $query->where('niveau', $periode->niveau);
            }

            $etudiants = $query->limit(500)->get();

            foreach ($etudiants as $etudiant) {
                $service->notifierEtudiant(
                    $etudiant,
                    'periode.ouverte',
                    'Ouverture de la période d\'inscription',
                    "La période d'inscription « {$periode->nom} » est désormais ouverte. Vous pouvez déposer votre candidature ou consulter votre situation en ligne.",
                    route('etudiant.suivi'),
                    [
                        'periode_id' => $periode->id,
                        'periode_nom' => $periode->nom,
                        'date_fin' => $periode->closed_at?->format('d/m/Y'),
                    ],
                    0,
                    'periode.ouverte:'.$periode->id.':'.$etudiant->id
                );
            }
        } catch (Throwable) {
            // Fail-soft
        }
    }
}
