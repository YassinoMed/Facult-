<?php

namespace App\Modules\Evaluation\Services;

use App\Support\Permissions;

/**
 * §2.7 — diagnostic de faisabilité de la séparation des pouvoirs PAR RÔLE.
 *
 * Le circuit de gouvernance garantit déjà que les ACTEURS sont des personnes
 * distinctes. La séparation par rôle est une exigence plus forte : les rôles
 * qui portent les étapes (préparer, certifier, approuver, publier) doivent
 * être différents les uns des autres.
 *
 * Cette règle n'est PAS activée par défaut : la matrice de rôles livrée ne
 * permet pas de l'honorer (`admin_scolarite` porte `evaluations.certify`,
 * `evaluations.approve` ET `evaluations.publish`, et aucun autre rôle ne
 * porte ces permissions). Activer la règle sans modifier la matrice
 * bloquerait toute publication.
 *
 * Ce service rend la décision mesurable au lieu de la laisser implicite : il
 * énumère les rôles éligibles par étape, détermine si une attribution
 * distincte existe, et propose sinon les rôles à créer ou à scinder.
 */
class ExamSeparationRolesDiagnosticService
{
    /** Étape du circuit => permission que le rôle doit porter. */
    public const ETAPES = [
        'preparateur' => 'evaluations.update',
        'certificateur' => 'evaluations.certify',
        'approbateur' => 'evaluations.approve',
        'publicateur' => 'evaluations.publish',
    ];

    /**
     * Compte technique : porte toutes les permissions. Il est exclu des
     * propositions d'attribution — un rôle qui peut tout faire ne sépare
     * rien — mais reste signalé pour que la décision soit explicite.
     */
    public const ROLE_TECHNIQUE = 'super_admin';

    public function estActif(): bool
    {
        return (bool) config('modules.evaluation.separation_pouvoirs_par_role', false);
    }

    /**
     * Rôles portant la permission de chaque étape, hors compte technique.
     *
     * @param  array<string, array<int, string>>|null  $matrice
     * @return array<string, array<int, string>>
     */
    public function rolesParEtape(?array $matrice = null): array
    {
        $matrice ??= $this->matriceCourante();

        $resultat = [];
        foreach (self::ETAPES as $etape => $permission) {
            $resultat[$etape] = [];
            foreach ($matrice as $role => $permissions) {
                if ($role === self::ROLE_TECHNIQUE) {
                    continue;
                }
                if (in_array($permission, $permissions, true)) {
                    $resultat[$etape][] = $role;
                }
            }
            sort($resultat[$etape]);
        }

        return $resultat;
    }

    /**
     * Attribution distincte la plus simple : une étape = un rôle, aucun rôle
     * réutilisé. Les deux approbations de niveau partagent volontairement une
     * étape (la séparation entre approbateur 1 et 2 reste garantie au niveau
     * des personnes).
     *
     * @param  array<string, array<int, string>>|null  $matrice
     * @return array<string, string>|null
     */
    public function proposition(?array $matrice = null): ?array
    {
        $rolesParEtape = $this->rolesParEtape($matrice);

        return $this->chercherAttribution(array_keys(self::ETAPES), $rolesParEtape, [], []);
    }

    /**
     * Diagnostic complet, prêt à être affiché ou sérialisé.
     *
     * @param  array<string, array<int, string>>|null  $matrice
     * @return array{
     *     mode_actif: bool,
     *     roles_par_etape: array<string, array<int, string>>,
     *     etapes_sans_role: array<int, string>,
     *     faisable: bool,
     *     proposition: array<string, string>|null,
     *     actions_requises: array<int, string>,
     *     role_technique: string
     * }
     */
    public function diagnostic(?array $matrice = null): array
    {
        $rolesParEtape = $this->rolesParEtape($matrice);
        $proposition = $this->proposition($matrice);

        $sansRole = array_keys(array_filter(
            $rolesParEtape,
            fn (array $roles): bool => $roles === []
        ));

        return [
            'mode_actif' => $this->estActif(),
            'roles_par_etape' => $rolesParEtape,
            'etapes_sans_role' => $sansRole,
            'faisable' => $proposition !== null,
            'proposition' => $proposition,
            'actions_requises' => $proposition !== null ? [] : $this->actionsRequises($rolesParEtape),
            'role_technique' => self::ROLE_TECHNIQUE,
        ];
    }

    /**
     * Message opérationnel pour le décideur : ce qu'il faut changer dans la
     * matrice pour que la règle devienne activable.
     *
     * @param  array<string, array<int, string>>  $rolesParEtape
     * @return array<int, string>
     */
    private function actionsRequises(array $rolesParEtape): array
    {
        $actions = [];

        foreach ($rolesParEtape as $etape => $roles) {
            $permission = self::ETAPES[$etape];

            if ($roles === []) {
                $actions[] = "Étape « {$etape} » : créer un rôle portant « {$permission} » (aucun rôle ne la porte aujourd'hui).";

                continue;
            }

            if (count($roles) === 1) {
                $actions[] = "Étape « {$etape} » : créer un rôle distinct portant « {$permission} » — seul « {$roles[0]} » la porte aujourd'hui, et ce rôle est déjà requis par une autre étape.";

                continue;
            }

            $actions[] = "Étape « {$etape} » : réserver l'un de (".implode(', ', $roles).") à cette seule étape, ou créer un rôle distinct portant « {$permission} ».";
        }

        return $actions;
    }

    /**
     * Recherche exhaustive (4 étapes, matrice réduite) d'une attribution sans
     * rôle réutilisé.
     *
     * @param  array<int, string>  $etapes
     * @param  array<string, array<int, string>>  $rolesParEtape
     * @param  array<string, string>  $attribution
     * @param  array<int, string>  $utilises
     * @return array<string, string>|null
     */
    private function chercherAttribution(array $etapes, array $rolesParEtape, array $attribution, array $utilises): ?array
    {
        if ($etapes === []) {
            return $attribution;
        }

        $etape = array_shift($etapes);

        foreach ($rolesParEtape[$etape] ?? [] as $role) {
            if (in_array($role, $utilises, true)) {
                continue;
            }

            $resultat = $this->chercherAttribution(
                $etapes,
                $rolesParEtape,
                $attribution + [$etape => $role],
                [...$utilises, $role],
            );

            if ($resultat !== null) {
                return $resultat;
            }
        }

        return null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function matriceCourante(): array
    {
        $matrice = [];
        foreach (Permissions::ROLES as $role) {
            $matrice[$role] = Permissions::pourRole($role);
        }

        return $matrice;
    }
}
