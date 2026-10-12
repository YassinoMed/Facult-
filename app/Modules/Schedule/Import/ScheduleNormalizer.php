<?php

namespace App\Modules\Schedule\Import;

use App\Modules\Schedule\Models\ScheduleSlot;

/**
 * Normalisation (§4-5/§12) : jours FR→EN, heures H:i, types, semestre
 * S1→1, année 2026/2027→2026-2027. Valeurs centralisées (constantes du
 * modèle — jamais hard-codées en plusieurs endroits).
 */
final class ScheduleNormalizer
{
    public const JOURS_FR = [
        'lundi' => 'monday',
        'mardi' => 'tuesday',
        'mercredi' => 'wednesday',
        'jeudi' => 'thursday',
        'vendredi' => 'friday',
        'samedi' => 'saturday',
        'dimanche' => 'sunday',
    ];

    /**
     * @param  array{entete: array<string, mixed>, lignes: array<int, array<string, mixed>>}  $brut
     * @return array{entete: array<string, mixed>, lignes: array<int, array<string, mixed>>}
     */
    public static function normaliser(array $brut): array
    {
        $entete = $brut['entete'];

        $semestre = mb_strtoupper(trim((string) ($entete['semester'] ?? '')));
        $semestre = ltrim($semestre, 'S');

        $lignes = [];
        foreach ($brut['lignes'] as $ligne) {
            $jour = mb_strtolower(trim((string) ($ligne['day'] ?? '')));
            $jour = self::JOURS_FR[$jour] ?? $jour;

            $type = mb_strtolower(trim((string) ($ligne['type'] ?? 'course')));
            $type = in_array($type, ['cours'], true) ? 'course' : $type;

            $lignes[] = array_merge($ligne, [
                'day' => $jour,
                'start_time' => self::heure($ligne['start_time'] ?? ''),
                'end_time' => self::heure($ligne['end_time'] ?? ''),
                'type' => $type,
                'course' => trim((string) ($ligne['course'] ?? '')),
                'teacher' => isset($ligne['teacher']) && trim((string) $ligne['teacher']) !== '' ? trim((string) $ligne['teacher']) : null,
                'room' => isset($ligne['room']) && trim((string) $ligne['room']) !== '' ? trim((string) $ligne['room']) : null,
            ]);
        }

        return [
            'entete' => [
                'academic_year' => str_replace('/', '-', trim((string) ($entete['academic_year'] ?? ''))),
                'semester' => $semestre,
                'group' => mb_strtoupper(trim((string) ($entete['group'] ?? ''))),
                'starts_at' => isset($entete['starts_at']) && trim((string) $entete['starts_at']) !== '' ? trim((string) $entete['starts_at']) : null,
                'ends_at' => isset($entete['ends_at']) && trim((string) $entete['ends_at']) !== '' ? trim((string) $entete['ends_at']) : null,
            ],
            'lignes' => $lignes,
        ];
    }

    public static function joursValides(): string
    {
        return implode(', ', ScheduleSlot::JOURS);
    }

    private static function heure(mixed $valeur): string
    {
        $valeur = trim((string) $valeur);

        // Accepte 8:00, 08h00, 08h30 → 08:00.
        $valeur = str_replace('h', ':', $valeur);

        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $valeur, $m) === 1) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }

        return $valeur;
    }
}
