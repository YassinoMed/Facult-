<?php

namespace App\Modules\Evaluation\Services;

use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\SessionExamen;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Cycle de vie des sessions (§13.2/13.13) : matrice TRANSITIONS +
 * contrôle de readiness bloquant avant ouverture + audit.
 */
class SessionExamenService
{
    /**
     * @return array{epreuves: int, sans_salle: int, sans_surveillant: int, sans_candidat: int, bloquants: array<int, string>}
     */
    public function readiness(SessionExamen $session): array
    {
        $epreuves = $session->epreuves()->withCount(['surveillants', 'candidats'])->get();

        $sansSalle = $epreuves->whereNull('salle_id')->count();
        $sansSurveillant = $epreuves->filter(fn ($e) => $e->surveillants_count === 0)->count();
        $sansCandidat = $epreuves->filter(fn ($e) => $e->candidats_count === 0)->count();

        $bloquants = [];
        if ($epreuves->isEmpty()) {
            $bloquants[] = 'Aucune épreuve planifiée.';
        }
        if ($sansSalle > 0) {
            $bloquants[] = "{$sansSalle} épreuve(s) sans salle.";
        }
        if ($sansSurveillant > 0) {
            $bloquants[] = "{$sansSurveillant} épreuve(s) sans surveillant.";
        }
        if ($sansCandidat > 0) {
            $bloquants[] = "{$sansCandidat} épreuve(s) sans candidat.";
        }

        return [
            'epreuves' => $epreuves->count(),
            'sans_salle' => $sansSalle,
            'sans_surveillant' => $sansSurveillant,
            'sans_candidat' => $sansCandidat,
            'bloquants' => $bloquants,
        ];
    }

    public function transiter(SessionExamen $session, string $cible): SessionExamen
    {
        return DB::transaction(function () use ($session, $cible) {
            $session->refresh();

            if (! $session->peutTransiterVers($cible)) {
                throw new DomainException("Transition {$session->statut} → {$cible} interdite.");
            }

            if ($cible === 'open') {
                $readiness = $this->readiness($session);

                if ($readiness['bloquants'] !== []) {
                    throw new DomainException('Ouverture bloquée : '.implode(' ', $readiness['bloquants']));
                }
            }

            $session->update([
                'statut' => $cible,
                'verrouillee_at' => in_array($cible, ['locked', 'archived'], true) ? now() : $session->verrouillee_at,
            ]);

            EvaluationChanged::dispatch("session.{$cible}", $session->id);

            return $session->fresh();
        });
    }
}
