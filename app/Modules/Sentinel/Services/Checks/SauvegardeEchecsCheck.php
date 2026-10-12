<?php

namespace App\Modules\Sentinel\Services\Checks;

use App\Contracts\SentinelCheckInterface;
use App\Models\DatabaseBackup;

/**
 * SAUVEGARDE_ECHECS (erreur) : échecs de sauvegarde sur les dernières
 * 24 h (agrégés en UN incident : pas d'alerte par échec, la notification
 * auteur existe déjà côté job). Cockpit seul, sans notification de masse.
 */
class SauvegardeEchecsCheck implements SentinelCheckInterface
{
    public function code(): string
    {
        return 'SAUVEGARDE_ECHECS';
    }

    public function categorie(): string
    {
        return 'sauvegarde';
    }

    public function gravite(): string
    {
        return 'erreur';
    }

    public function module(): ?string
    {
        return 'systeme';
    }

    public function controler(): array
    {
        $echecs = DatabaseBackup::whereIn('status', ['FAILED', 'VERIFICATION_FAILED'])
            ->where('created_at', '>=', now()->subDay())
            ->count();

        if ($echecs === 0) {
            return [];
        }

        return [[
            'titre' => "{$echecs} échec(s) de sauvegarde sur 24 h",
            'diagnostic' => "{$echecs} ligne(s) FAILED/VERIFICATION_FAILED créées depuis hier (voir l'historique des sauvegardes).",
            'recommandation' => 'Consulter les codes d’erreur, corriger la cause (binaire, espace, permissions) puis relancer.',
        ]];
    }
}
