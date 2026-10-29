<?php

namespace App\Support;

use App\Models\User;
use App\Services\ParametreService;
use Throwable;

/**
 * Politique MFA (LOOP-SECURITY-REMEDIATION-01) : décide si un compte
 * back-office DOIT utiliser le double facteur TOTP.
 *
 * Deux niveaux :
 *  1. rôles à privilèges (`security.mfa_required_roles`) — obligatoire
 *     en dur, non débrayable par paramètre applicatif ;
 *  2. paramètre `securite.mfa_obligatoire_admin` — étend l'obligation à
 *     TOUS les comptes back-office lorsqu'il est activé.
 */
final class MfaPolicy
{
    /**
     * @return array<int, string>
     */
    public static function rolesObligatoires(): array
    {
        $roles = (array) config('security.mfa_required_roles', [
            'super_admin', 'admin_scolarite', 'admin_faculte',
        ]);

        return array_values(array_filter(array_map(
            fn ($role) => trim((string) $role),
            $roles
        )));
    }

    public static function exigePour(?User $utilisateur): bool
    {
        if ($utilisateur === null || $utilisateur->role === null) {
            return false;
        }

        if (in_array((string) $utilisateur->role, self::rolesObligatoires(), true)) {
            return true;
        }

        // Paramètre global (lecture cachée, repli fermé en cas de panne :
        // l'absence de valeur ne doit jamais casser la connexion).
        try {
            return (bool) app(ParametreService::class)->get('securite.mfa_obligatoire_admin', false);
        } catch (Throwable) {
            return false;
        }
    }
}
