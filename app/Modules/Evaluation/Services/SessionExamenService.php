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
     * Contrôles de cohérence avant ouverture. Base historique (§13.2)
     * + LOOP-EXAM-PREPARATION-01 (G6) : capacité par salle et dérive
     * de population depuis la validation des listes.
     *
     * @return array{epreuves: int, sans_salle: int, sans_surveillant: int, sans_candidat: int, capacite_insuffisante: int, population_modifiee: int, bloquants: array<int, string>}
     */
    public function readiness(SessionExamen $session): array
    {
        $epreuves = $session->epreuves()->withCount(['surveillants', 'candidats'])->get();
        $preparation = app(PreparationExamenService::class);

        $sansSalle = $epreuves->whereNull('salle_id')->count();
        $sansSurveillant = $epreuves->filter(fn ($e) => $e->surveillants_count === 0)->count();
        $sansCandidat = $epreuves->filter(fn ($e) => $e->candidats_count === 0)->count();

        // G6 : capacité réelle par salle (jamais inventée) + dérive de
        // population (uniquement si un snapshot de validation existe).
        $capaciteInsuffisante = 0;
        $populationModifiee = 0;

        foreach ($epreuves as $epreuve) {
            $candidats = $epreuve->candidats()->with('salle')->get()
                ->groupBy(fn ($c) => $c->salle_id ?? $epreuve->salle_id);

            foreach ($candidats as $salleId => $groupe) {
                if ($salleId === null) {
                    continue;
                }

                $salle = $groupe->first()->salle ?? $epreuve->salle()->find($salleId);

                if ($salle !== null && (int) $salle->capacite > 0 && $groupe->count() > (int) $salle->capacite) {
                    $capaciteInsuffisante++;
                }
            }

            if ($preparation->populationModifiee($epreuve)) {
                $populationModifiee++;
            }
        }

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
        if ($capaciteInsuffisante > 0) {
            $bloquants[] = "{$capaciteInsuffisante} épreuve(s) en sur-capacité de salle.";
        }
        if ($populationModifiee > 0) {
            $bloquants[] = "{$populationModifiee} liste(s) de candidats modifiée(s) depuis la validation — revalider la population.";
        }

        return [
            'epreuves' => $epreuves->count(),
            'sans_salle' => $sansSalle,
            'sans_surveillant' => $sansSurveillant,
            'sans_candidat' => $sansCandidat,
            'capacite_insuffisante' => $capaciteInsuffisante,
            'population_modifiee' => $populationModifiee,
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
