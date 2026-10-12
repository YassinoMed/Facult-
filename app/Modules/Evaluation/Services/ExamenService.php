<?php

namespace App\Modules\Evaluation\Services;

use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\Examen;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Transitions d'examen (Phase 10) : matrice serveur + transaction +
 * event d'audit. Le contrôleur garde Gate + périmètre.
 */
class ExamenService
{
    public function transiter(Examen $examen, string $cible): Examen
    {
        return DB::transaction(function () use ($examen, $cible) {
            $examen->refresh();

            if ($examen->estCritique() && $cible !== 'archived') {
                throw new DomainException('Examen verrouillé.');
            }

            if (! $examen->peutTransiterVers($cible)) {
                throw new DomainException("Transition {$examen->statut} → {$cible} interdite.");
            }

            $examen->update([
                'statut' => $cible,
                'published_at' => $cible === 'published' ? now() : $examen->published_at,
            ]);

            EvaluationChanged::dispatch('examen.statut', $examen->id);

            return $examen->fresh();
        });
    }
}
