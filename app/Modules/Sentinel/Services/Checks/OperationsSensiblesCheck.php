<?php

namespace App\Modules\Sentinel\Services\Checks;

use App\Contracts\SentinelCheckInterface;
use App\Models\User;

/**
 * Platform Sentinel — contrôle de la sécurité et des comptes à privilèges.
 * Détecte l'absence de super-administrateur actif ou les incohérences d'accès.
 */
class OperationsSensiblesCheck implements SentinelCheckInterface
{
    public function code(): string
    {
        return 'SECURITE_COMPTES_ADMIN';
    }

    public function categorie(): string
    {
        return 'securite';
    }

    public function gravite(): string
    {
        return 'critique';
    }

    public function module(): ?string
    {
        return 'administration';
    }

    public function controler(): array
    {
        $constats = [];

        try {
            $superAdminsActifs = User::where('role', 'super_admin')
                ->where('actif', true)
                ->count();

            if ($superAdminsActifs === 0) {
                $constats[] = [
                    'titre' => 'Aucun compte Super Administrateur actif',
                    'diagnostic' => 'La plateforme ne compte aucun utilisateur avec le rôle `super_admin` et le statut `actif=1`.',
                    'recommandation' => 'Créer ou réactiver immédiatement un compte super-administrateur pour garantir la gouvernance du système.',
                ];
            }
        } catch (\Throwable) {
        }

        return $constats;
    }
}
