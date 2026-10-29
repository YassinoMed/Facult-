<?php

namespace App\Modules\Schedule\Services;

use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;

/**
 * Service de suivi pédagogique des plannings (volumes horaires prévus vs planifiés, charges enseignants).
 */
class SchedulePedagogieService
{
    /**
     * Calcule le bilan des volumes horaires pour un enseignement donné :
     * compare les heures prévues (CM, TD, TP) aux heures planifiées dans les plannings actifs.
     *
     * @return array{
     *     cm: array{prevu: float, planifie: float, reste: float, statut: string},
     *     td: array{prevu: float, planifie: float, reste: float, statut: string},
     *     tp: array{prevu: float, planifie: float, reste: float, statut: string},
     *     total: array{prevu: float, planifie: float, reste: float, statut: string}
     * }
     */
    public function bilanVolumes(Enseignement $enseignement, ?int $scheduleId = null): array
    {
        $slots = ScheduleSlot::query()
            ->where('status', '!=', 'cancelled')
            ->where(function ($q) use ($enseignement) {
                $q->where('enseignement_id', $enseignement->id)
                    ->orWhere(function ($sub) use ($enseignement) {
                        $sub->whereNull('enseignement_id')
                            ->where('matiere_id', $enseignement->matiere_id);
                    });
            })
            ->when($scheduleId !== null, fn ($q) => $q->where('schedule_id', $scheduleId))
            ->get();

        $planifie = [
            'course' => 0.0,
            'td' => 0.0,
            'tp' => 0.0,
        ];

        foreach ($slots as $slot) {
            $type = $slot->type;
            if (! isset($planifie[$type])) {
                $type = 'course';
            }

            // Facteur de récurrence (quinzaine = 0.5 d'équivalent hebdomadaire)
            $coef = in_array($slot->semaine_parite, ['paire', 'impaire'], true) ? 0.5 : 1.0;
            $planifie[$type] += $slot->dureeHeures() * $coef;
        }

        // Semestre standard = ~14 semaines de cours
        $nbSemaines = 14;

        $volCmPlanifie = round($planifie['course'] * $nbSemaines, 1);
        $volTdPlanifie = round($planifie['td'] * $nbSemaines, 1);
        $volTpPlanifie = round($planifie['tp'] * $nbSemaines, 1);

        $cmPrevu = (float) ($enseignement->volume_cm ?? 0);
        $tdPrevu = (float) ($enseignement->volume_td ?? 0);
        $tpPrevu = (float) ($enseignement->volume_tp ?? 0);

        $calculerLigne = function (float $prevu, float $fait) {
            $reste = round($prevu - $fait, 1);
            $statut = match (true) {
                $prevu > 0 && $reste === 0.0 => 'complet',
                $reste < 0 => 'depassement',
                $fait > 0 => 'partiel',
                default => 'non_commence',
            };

            return [
                'prevu' => $prevu,
                'planifie' => $fait,
                'reste' => $reste,
                'statut' => $statut,
            ];
        };

        $totalPrevu = $cmPrevu + $tdPrevu + $tpPrevu;
        $totalPlanifie = $volCmPlanifie + $volTdPlanifie + $volTpPlanifie;

        return [
            'cm' => $calculerLigne($cmPrevu, $volCmPlanifie),
            'td' => $calculerLigne($tdPrevu, $volTdPlanifie),
            'tp' => $calculerLigne($tpPrevu, $volTpPlanifie),
            'total' => $calculerLigne($totalPrevu, $totalPlanifie),
        ];
    }

    /**
     * Analyse la charge d'un enseignant et alerte en cas de dépassement de sa borne max ou sous-charge.
     *
     * @return array{
     *     charge_hebdo: float,
     *     charge_min: float,
     *     charge_max: float,
     *     surcharge: bool,
     *     sous_charge: bool,
     *     avertissements: array<int, string>
     * }
     */
    public function analyseChargeEnseignant(Teacher $teacher, ?int $scheduleId = null): array
    {
        $chargeHebdo = $teacher->chargeHebdoPlanifiee($scheduleId);
        $min = (float) ($teacher->charge_hebdo_min ?? 4.0);
        $max = (float) ($teacher->charge_hebdo_max ?? 18.0);

        $surcharge = $chargeHebdo > $max;
        $sousCharge = $chargeHebdo > 0 && $chargeHebdo < $min;

        $avertissements = [];
        if ($surcharge) {
            $avertissements[] = "L'enseignant {$teacher->nomComplet()} est en surcharge hebdomadaire ({$chargeHebdo}h planifiées pour un maximum autorisé de {$max}h).";
        }
        if ($sousCharge) {
            $avertissements[] = "L'enseignant {$teacher->nomComplet()} est en sous-charge hebdomadaire ({$chargeHebdo}h planifiées pour un minimum requis de {$min}h).";
        }

        return [
            'charge_hebdo' => $chargeHebdo,
            'charge_min' => $min,
            'charge_max' => $max,
            'surcharge' => $surcharge,
            'sous_charge' => $sousCharge,
            'avertissements' => $avertissements,
        ];
    }
}
