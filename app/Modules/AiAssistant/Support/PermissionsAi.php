<?php

namespace App\Modules\AiAssistant\Support;

/**
 * Matrice RBAC du module AI-ASSISTANT (Lot 1) — même convention de
 * permissions pointées + Gates que la matrice centrale (App\Permissions),
 * mais module-LOCAL : le fichier central est modifié par un lot
 * concurrent ; aucune permission IA n'y est injectée en v1 (réserve
 * documentée : non visible dans la matrice d'attribution centrale).
 *
 * Rôles Fermés, identiques au RBAC central.
 */
final class PermissionsAi
{
    public const ACCEDER = 'ai.acceder';

    public const DEVELOPPEUR = 'ai.developpeur';

    public const METIER = 'ai.metier';

    public const DOCUMENTS_RECHERCHER = 'ai.documents.rechercher';

    public const DOCUMENTS_RESTREINTS = 'ai.documents.restreints';

    public const ACTION_PREPARER = 'ai.action.preparer';

    public const ACTION_APPROUVER = 'ai.action.approuver';

    public const AUDIT_CONSULTER = 'ai.audit.consulter';

    public const ADMIN_CONFIGURER = 'ai.admin.configurer';

    /** @var list<string> */
    public const TOUTES = [
        self::ACCEDER,
        self::DEVELOPPEUR,
        self::METIER,
        self::DOCUMENTS_RECHERCHER,
        self::DOCUMENTS_RESTREINTS,
        self::ACTION_PREPARER,
        self::ACTION_APPROUVER,
        self::AUDIT_CONSULTER,
        self::ADMIN_CONFIGURER,
    ];

    /**
     * Rôle → permissions (défaut de config ; l'exploitation peut se
     * limiter étroitement). Consultation = accès lecture + docs.
     *
     * @var array<string, list<string>>
     */
    public const MATRICE = [
        'super_admin' => self::TOUTES,
        'admin_scolarite' => [
            self::ACCEDER,
            self::DEVELOPPEUR,
            self::METIER,
            self::DOCUMENTS_RECHERCHER,
            self::DOCUMENTS_RESTREINTS,
            self::ACTION_PREPARER,
            self::ACTION_APPROUVER,
            self::AUDIT_CONSULTER,
        ],
        'agent_scolarite' => [
            self::ACCEDER,
            self::METIER,
            self::DOCUMENTS_RECHERCHER,
            self::ACTION_PREPARER,
        ],
        'admin_faculte' => [
            self::ACCEDER,
            self::METIER,
            self::DOCUMENTS_RECHERCHER,
            self::ACTION_PREPARER,
            self::ACTION_APPROUVER,
        ],
        'consultation' => [
            self::ACCEDER,
            self::DOCUMENTS_RECHERCHER,
        ],
    ];

    /**
     * @return list<string>
     */
    public static function pourRole(string $role): array
    {
        return self::MATRICE[$role] ?? [];
    }

    public static function permise(string $permission, string $role): bool
    {
        return in_array($permission, self::pourRole($role), true);
    }
}
