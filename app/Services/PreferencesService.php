<?php

namespace App\Services;

use App\Models\PreferenceUtilisateur;
use Illuminate\Support\Facades\Cache;

/**
 * Préférences utilisateur (Phase 7 §7.4) : lecture cachée 1 h par
 * utilisateur, écriture validée. Appliquées : lignes par page des
 * registres, densité des tableaux (classe `densite-compact`).
 */
class PreferencesService
{
    /**
     * @return array{densite: string, lignes_par_page: int}
     */
    public function lire(int $userId): array
    {
        return Cache::remember("preferences:{$userId}", 3600, function () use ($userId) {
            $preferences = PreferenceUtilisateur::where('user_id', $userId)->first();

            return [
                'densite' => $preferences?->densite ?? 'confortable',
                'lignes_par_page' => max(5, min(100, (int) ($preferences?->lignes_par_page ?? 12))),
            ];
        });
    }

    /** @param array{densite?: string, lignes_par_page?: int} $donnees */
    public function ecrire(int $userId, array $donnees): PreferenceUtilisateur
    {
        $preferences = PreferenceUtilisateur::updateOrCreate(
            ['user_id' => $userId],
            [
                'densite' => in_array($donnees['densite'] ?? null, PreferenceUtilisateur::DENSITES, true)
                    ? $donnees['densite'] : 'confortable',
                'lignes_par_page' => max(5, min(100, (int) ($donnees['lignes_par_page'] ?? 12))),
            ],
        );

        Cache::forget("preferences:{$userId}");

        return $preferences;
    }
}
