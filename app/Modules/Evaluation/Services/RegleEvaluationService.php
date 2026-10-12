<?php

namespace App\Modules\Evaluation\Services;

use App\Modules\Evaluation\Models\RegleEvaluation;

/**
 * Règles de calcul versionnées par année / programme (§13).
 * Socle : seuils du cœur ; surcharge locale si une règle active existe.
 */
class RegleEvaluationService
{
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    public static function flushCache(): void
    {
        self::$cache = [];
    }

    /**
     * @return array{seuil_validation: float, seuil_rattrapage: float, note_eliminatoire: ?float, compensation_ecue: bool, compensation_ue: bool, compensation_semestre: bool, arrondi: string, regle_id: ?int, version: ?int}
     */
    public function resolve(int $anneeId, ?int $programmeId = null): array
    {
        $cle = "{$anneeId}_".($programmeId ?? 'null');
        if (isset(self::$cache[$cle])) {
            return self::$cache[$cle];
        }

        $regle = RegleEvaluation::where('annee_universitaire_id', $anneeId)
            ->where('actif', true)
            ->when($programmeId !== null, fn ($q) => $q->where(fn ($s) => $s->where('programme_id', $programmeId)->orWhereNull('programme_id')))
            ->when($programmeId === null, fn ($q) => $q->whereNull('programme_id'))
            ->orderByRaw('programme_id IS NULL')
            ->orderByDesc('version')
            ->first();

        if ($regle === null) {
            return self::$cache[$cle] = [
                'seuil_validation' => 10.0,
                'seuil_rattrapage' => 8.0,
                'note_eliminatoire' => null,
                'compensation_ecue' => true,
                'compensation_ue' => true,
                'compensation_semestre' => true,
                'arrondi' => '2dec',
                'regle_id' => null,
                'version' => null,
            ];
        }

        return self::$cache[$cle] = [
            'seuil_validation' => (float) $regle->seuil_validation,
            'seuil_rattrapage' => (float) $regle->seuil_rattrapage,
            'note_eliminatoire' => $regle->note_eliminatoire !== null ? (float) $regle->note_eliminatoire : null,
            'compensation_ecue' => (bool) $regle->compensation_ecue,
            'compensation_ue' => (bool) $regle->compensation_ue,
            'compensation_semestre' => (bool) $regle->compensation_semestre,
            'arrondi' => $regle->arrondi,
            'regle_id' => $regle->id,
            'version' => $regle->version,
        ];
    }

    public function arrondir(?float $valeur, string $arrondi): ?float
    {
        if ($valeur === null) {
            return null;
        }

        return match ($arrondi) {
            '1dec' => round($valeur, 1),
            'entier' => (float) round($valeur),
            default => round($valeur, 2),
        };
    }
}
