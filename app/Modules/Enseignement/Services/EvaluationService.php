<?php

namespace App\Modules\Enseignement\Services;

use App\Models\Matiere;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Support\Facades\DB;

/**
 * Évaluation des ECUE (§23-24) : composantes (somme = 100) et
 * responsable pédagogique. Prépare les phases Notes/Examens.
 */
class EvaluationService
{
    /**
     * @param  array<int, array{code: string, libelle: string, pourcentage: float}>  $composantes
     *
     * @throws \DomainException
     */
    public function definirComposantes(Matiere $matiere, array $composantes, ?int $auteurId): void
    {
        $total = round(array_sum(array_column($composantes, 'pourcentage')), 2);

        if (abs($total - 100.0) > 0.01) {
            throw new \DomainException("La somme des composantes doit être 100 % (actuel : {$total} %).");
        }

        foreach ($composantes as $composante) {
            if (($composante['pourcentage'] ?? -1) < 0) {
                throw new \DomainException('Pourcentage négatif interdit.');
            }
        }

        DB::transaction(function () use ($matiere, $composantes) {
            $matiere->composantes()->delete();

            foreach ($composantes as $composante) {
                $matiere->composantes()->create([
                    'code' => $composante['code'],
                    'libelle' => $composante['libelle'],
                    'pourcentage' => $composante['pourcentage'],
                ]);
            }
        });
    }

    /**
     * @throws \DomainException
     */
    public function definirResponsable(Matiere $matiere, ?int $teacherId, ?int $auteurId): void
    {
        if ($teacherId !== null) {
            $teacher = Teacher::findOrFail($teacherId);

            if (! $teacher->actif) {
                throw new \DomainException('Responsable inactif interdit.');
            }
        }

        $matiere->update(['responsable_teacher_id' => $teacherId]);
    }
}
