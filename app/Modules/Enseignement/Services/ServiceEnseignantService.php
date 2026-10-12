<?php

namespace App\Modules\Enseignement\Services;

use App\Modules\Enseignement\Models\AffectationEnseignement;
use App\Modules\Enseignement\Models\SeuilService;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Support\Collection;

/**
 * Services enseignants (§18-22) : charges prévue/affectée par type,
 * seuils (min/cible/max), sur/sous-charge. Calculs serveur.
 */
class ServiceEnseignantService
{
    /**
     * Charge d'un enseignant sur une année : prévue (volumes des
     * enseignements où il est affecté, au prorata de ses heures) +
     * affectée (somme de ses affectations actives).
     *
     * @return array{cm: float, td: float, tp: float, projet: float, autre: float, total: float, affecte: float}
     */
    public function charge(int $teacherId, ?int $anneeId): array
    {
        $affectations = AffectationEnseignement::with('enseignement')
            ->where('teacher_id', $teacherId)->where('statut', 'active')
            ->when($anneeId !== null, fn ($q) => $q->whereHas('enseignement',
                fn ($e) => $e->where('annee_universitaire_id', $anneeId)))
            ->get();

        $parType = ['cm' => 0.0, 'td' => 0.0, 'tp' => 0.0, 'projet' => 0.0, 'autre' => 0.0];
        $affecte = 0.0;

        foreach ($affectations as $affectation) {
            $enseignement = $affectation->enseignement;

            if ($enseignement === null) {
                continue;
            }

            $affecte += (float) $affectation->volume_heures;
            $cle = $this->cleType($enseignement->type?->code);
            $parType[$cle] += (float) $affectation->volume_heures;
        }

        $parType['total'] = round(array_sum($parType), 1);
        $parType['affecte'] = round($affecte, 1);

        return $parType;
    }

    /**
     * Seuils applicables (grade > faculté > global), puis alertes.
     *
     * @return array{min: float, cible: float, max: float}
     */
    public function seuils(Teacher $teacher, ?int $anneeId): array
    {
        $candidats = SeuilService::orderByRaw(
            '(grade_id IS NOT NULL) DESC, (faculte_id IS NOT NULL) DESC'
        )
            ->when($teacher->grade_id !== null, fn ($q) => $q->where(
                fn ($w) => $w->where('grade_id', $teacher->grade_id)->orWhereNull('grade_id')))
            ->when($teacher->grade_id === null, fn ($q) => $q->whereNull('grade_id'))
            ->when($anneeId !== null, fn ($q) => $q->where(
                fn ($w) => $w->where('annee_universitaire_id', $anneeId)->orWhereNull('annee_universitaire_id')))
            ->first();

        if ($candidats === null) {
            return ['min' => 0.0, 'cible' => 0.0, 'max' => 0.0];
        }

        return [
            'min' => (float) $candidats->service_min,
            'cible' => (float) $candidats->service_cible,
            'max' => (float) $candidats->service_max,
        ];
    }

    /**
     * @return Collection<int, array{teacher: Teacher, total: float, statut: string}>
     */
    public function tableauServices(?int $anneeId, ?int $faculteId): Collection
    {
        return Teacher::where('actif', true)
            // Périmètre via le département (Teacher n'a pas de faculte_id
            // propre) : seuls les rattachés à la faculté, jamais les
            // autres — les sans-département sont signalés en anomalies.
            ->when($faculteId !== null, fn ($q) => $q->whereHas('departement',
                fn ($d) => $d->where('faculte_id', $faculteId)))
            ->orderBy('nom')->get()
            ->map(function (Teacher $teacher) use ($anneeId) {
                $charge = $this->charge($teacher->id, $anneeId);
                $seuils = $this->seuils($teacher, $anneeId);
                $total = $charge['total'];

                $statut = 'normal';

                if ($seuils['max'] > 0 && $total > $seuils['max']) {
                    $statut = 'surcharge';
                } elseif ($seuils['min'] > 0 && $total < $seuils['min']) {
                    $statut = 'sous-charge';
                }

                return ['teacher' => $teacher, 'charge' => $charge, 'seuils' => $seuils, 'statut' => $statut];
            });
    }

    private function cleType(?string $code): string
    {
        return match ($code) {
            'CM' => 'cm',
            'TD' => 'td',
            'TP' => 'tp',
            'PROJET' => 'projet',
            default => 'autre',
        };
    }
}
