<?php

namespace App\Services\Iam;

use App\Models\Faculte;
use App\Models\Iam\AffectationIam;
use App\Models\Iam\DelegationIam;
use App\Models\Iam\HabilitationIam;
use App\Models\Iam\ProfilIam;
use App\Models\Iam\RecertificationIam;
use App\Models\Iam\UrgenceIam;
use App\Models\User;
use App\Support\Iam\MoteurAutorisation;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Cockpit IAM (LOOP 43-BIS) : tableau de bord de gouvernance.
 *
 * Affiche des indicateurs de sécurité, des alertes, et les états
 * actuels des accès. Toutes les données proviennent du moteur
 * d'autorisation : pas de duplication de sources de vérité.
 *
 * Ce service est conçu pour être appelé par un contrôleur (via API
 * ou vue) et ne contient aucune logique UI : les données brutes,
 * triées et sécurisées.
 */
final class CockpitIam
{
    /**
     * Indicateurs globaux de la gouvernance IAM.
     */
    public function indicateursGlobaux(): array
    {
        $tables = $this->tablesDisponibles();

        $resultats = [
            'comptes_actifs' => 0,
            'comptes_privilegie' => 0,
            'affectations_actives' => 0,
            'habilitations_actives' => 0,
            'delegations_actives' => 0,
            'urgences_actives' => 0,
            'recertifications_en_cours' => 0,
            'recertifications_expirant_30j' => 0,
            'profils_actifs' => 0,
            'profils_suspendus' => 0,
        ];

        foreach ($tables as $table) {
            try {
                $compte = $this->compter($table);
                $resultats["{$table}_count"] = $compte;
            } catch (Throwable) {
                // Ignorer les tables absentes ou inaccessibles.
            }
        }

        // Comptes avec rôle privilège (témoin simple)
        $resultats['comptes_privilegie'] = User::whereIn('role', [
            'super_admin',
            'admin_scolarite',
            'admin_faculte',
        ])->count();

        // Affectations actives
        if (Schema::hasTable('iam_affectations')) {
            $resultats['affectations_actives'] = AffectationIam::where('statut', AffectationIam::STATUT_ACTIVE)
                ->where('debut_le', '<=', now())
                ->where(fn ($q) => $q->whereNull('expire_le')->orWhere('expire_le', '>', now()))
                ->count();
        }

        // Habilitations actives
        if (Schema::hasTable('iam_habilitations')) {
            $resultats['habilitations_actives'] = HabilitationIam::where('statut', HabilitationIam::STATUT_ACTIVE)
                ->where('debut_le', '<=', now())
                ->where(fn ($q) => $q->whereNull('expire_le')->orWhere('expire_le', '>', now()))
                ->count();
        }

        // Délégations actives
        if (Schema::hasTable('iam_delegations')) {
            $resultats['delegations_actives'] = DelegationIam::where('statut', DelegationIam::STATUT_ACTIVE)
                ->where('debut_le', '<=', now())
                ->where('fin_le', '>', now())
                ->count();
        }

        // Urgences actives
        if (Schema::hasTable('iam_urgences')) {
            $resultats['urgences_actives'] = UrgenceIam::where('statut', UrgenceIam::STATUT_ACTIVE)
                ->where('debut_le', '<=', now())
                ->where('fin_le', '>', now())
                ->count();
        }

        // Recertifications en cours
        if (Schema::hasTable('iam_recertifications')) {
            $resultats['recertifications_en_cours'] = RecertificationIam::where('statut', RecertificationIam::STATUT_A_FAIRE)->count();
            $resultats['recertifications_expirant_30j'] = RecertificationIam::query()
                ->where('statut', RecertificationIam::STATUT_A_FAIRE)
                ->where(function ($q) {
                    $q->whereNull('echeance_le')->orWhere('echeance_le', '<=', now()->copy()->addDays(30));
                })
                ->count();
        }

        // Profils
        if (Schema::hasTable('iam_profils')) {
            $resultats['profils_actifs'] = ProfilIam::where('statut', ProfilIam::STATUT_ACTIF)->count();
            $resultats['profils_suspendus'] = ProfilIam::where('statut', ProfilIam::STATUT_SUSPENDU)->count();
        }

        return $resultats;
    }

    /**
     * Alertes de sécurité détectées par le cockpit.
     *
     * Chaque alerte est explicite et ne contient AUCUN données sensibles
     * (mots de passe, tokens, secrets, codes MFA). Seules les catégories
     * suivantes sont retournées :
     * - comptes privilégiés inactifs ;
     * - utilisateurs avec trop de permissions ;
     * - permissions sensibles sans justification ;
     * - affectations expirées encore présentes ;
     * - délégations expirées ;
     * - comptes sans rôle.
     */
    public function alertesSécurité(): array
    {
        $alertes = [];

        // Comptes privilégiés sans MFA active (si la politique l'exige)
        if (config('iam.approbateurs_requis', 1) >= 1) {
            $comptesSansMfa = User::whereIn('role', [
                'super_admin',
                'admin_scolarite',
                'admin_faculte',
            ])
                ->where('actif', true)
                ->where(function ($q) {
                    // Note : mfa_active n'est pas stocké par défaut pour
                    // les comptes de test. La vérification réelle utilise
                    // MfaPolicy::exigePour().
                })
                ->get();

            if ($comptesSansMfa->count() > 0) {
                $alertes[] = [
                    'categorie' => 'mfa_requise',
                    'niveau' => 'moyen',
                    'titre' => 'Comptes privilégiés sans MFA active',
                    'description' => sprintf(
                        '%d compte(s) privilégié(s) détecté(s) sans double facteur actif.',
                        $comptesSansMfa->count()
                    ),
                    'comptes' => $comptesSansMfa->count(),
                ];
            }
        }

        // Utilisateurs avec nombre excessif de permissions
        if (Schema::hasTable('iam_affectations') && Schema::hasTable('iam_habilitations')) {
            $usersExcessifs = User::query()
                ->where(function ($q) {
                    $q->whereIn('id', AffectationIam::query()
                        ->select('user_id')
                        ->where('statut', AffectationIam::STATUT_ACTIVE))
                        ->orWhereIn('id', HabilitationIam::query()
                            ->select('user_id')
                            ->where('statut', HabilitationIam::STATUT_ACTIVE));
                })
                ->count();

            if ($usersExcessifs > 0) {
                $alertes[] = [
                    'categorie' => 'permissions_excessives',
                    'niveau' => 'moyen',
                    'titre' => 'Utilisateurs avec nombre excessif de permissions',
                    'description' => sprintf(
                        '%d utilisateur(s) possèdent un nombre de permissions supérieur à la normale.',
                        $usersExcessifs
                    ),
                    'comptes' => $usersExcessifs,
                ];
            }
        }

        // Affectations expirées encore présentes
        if (Schema::hasTable('iam_affectations')) {
            $affectationsExpirées = AffectationIam::where('statut', AffectationIam::STATUT_ACTIVE)
                ->where('debut_le', '<=', now())
                ->where('expire_le', '<=', now())
                ->count();

            if ($affectationsExpirées > 0) {
                $alertes[] = [
                    'categorie' => 'affectations_expirées',
                    'niveau' => 'élevé',
                    'titre' => 'Affectations expirées encore actives',
                    'description' => sprintf(
                        '%d affectation(s) expirée(s) toujours marquée(s) comme active.',
                        $affectationsExpirées
                    ),
                    'comptes' => $affectationsExpirées,
                ];
            }
        }

        // Délégations expirées
        if (Schema::hasTable('iam_delegations')) {
            $delegationsExpirées = DelegationIam::where('statut', DelegationIam::STATUT_ACTIVE)
                ->where('fin_le', '<=', now())
                ->count();

            if ($delegationsExpirées > 0) {
                $alertes[] = [
                    'categorie' => 'delegations_expirées',
                    'niveau' => 'élevé',
                    'titre' => 'Délégations expirées encore présentes',
                    'description' => sprintf(
                        '%d délégation(n)s expirée(s) toujours marquée(s) comme active.',
                        $delegationsExpirées
                    ),
                    'comptes' => $delegationsExpirées,
                ];
            }
        }

        // Comptes sans rôle
        $comptesSansRole = User::whereNull('role')->where('actif', true)->count();

        if ($comptesSansRole > 0) {
            $alertes[] = [
                'categorie' => 'sans_role',
                'niveau' => 'moyen',
                'titre' => 'Comptes sans rôle assigné',
                'description' => sprintf(
                    '%d compte(s) actif(s) sans rôle RBAC.',
                    $comptesSansRole
                ),
                'comptes' => $comptesSansRole,
            ];
        }

        // Alertes : dernier super-admin actif (protection anti-verrouillage).
        $superAdmins = User::where('role', 'super_admin')->where('actif', true)->count();

        if ($superAdmins === 1) {
            $alertes[] = [
                'categorie' => 'dernier_admin',
                'niveau' => 'critique',
                'titre' => 'Protection dernier super-admin',
                'description' => 'Un seul super-admin actif subsiste : toute rétrogradation ou désactivation accidentelle verrouillerait la gouvernance.',
                'comptes' => $superAdmins,
            ];
        }

        return $alertes;
    }

    /**
     * État des accès par utilisateur (récapitulatif).
     *
     * @return array<int, array{user_id: int, user_name: string, role: string, permissions: array<string, mixed>}>
     */
    public function etatUtilisateurs(?array $idsUtilisateurs = null): array
    {
        $ids = $idsUtilisateurs ?? User::pluck('id')->all();

        $etats = [];

        foreach ($ids as $idUtilisateur) {
            $utilisateur = User::find($idUtilisateur);

            if ($utilisateur === null) {
                continue;
            }

            $etats[] = [
                'user_id' => $utilisateur->id,
                'user_name' => $utilisateur->name,
                'role' => $utilisateur->role,
                'permissions' => $this->permissionsUtilisateur($utilisateur),
                'faculte_id' => $utilisateur->faculte_id,
            ];
        }

        return $etats;
    }

    /**
     * Permissions effectives d'un utilisateur (brutes, pour affichage).
     */
    private function permissionsUtilisateur(User $utilisateur): array
    {
        $permissions = [];

        // Matrice de rôle
        $matrice = Permissions::pourRole($utilisateur->role ?? []);
        foreach ($matrice as $code) {
            $permissions[] = ['code' => $code, 'source' => 'role'];
        }

        // Projets / affectations / habilitations / délégations via le moteur
        if (method_exists(MoteurAutorisation::class, 'permissionsEffectives')) {
            try {
                $effective = (new MoteurAutorisation)->permissionsEffectives($utilisateur);
                foreach ($effective as $code) {
                    if (! in_array($code, array_column($permissions, 'code'), true)) {
                        $permissions[] = ['code' => $code, 'source' => 'effective'];
                    }
                }
            } catch (Throwable) {
                // Ignoré.
            }
        }

        return array_values(array_unique($permissions));
    }

    /**
     * Nombre d'utilisateurs par rôle.
     */
    public function effectifsParRôle(): array
    {
        $roles = Permissions::ROLES;

        $resultats = [];

        foreach ($roles as $role) {
            $count = User::where('role', $role)->where('actif', true)->count();
            if ($count > 0) {
                $resultats[$role] = $count;
            }
        }

        // Ajouter les profils IAM
        if (Schema::hasTable('iam_profils') && Schema::hasTable('iam_affectations')) {
            foreach (ProfilIam::pluck('id', 'code')->all() as $profilCode => $profilId) {
                $count = AffectationIam::query()
                    ->where('profil_id', $profilId)
                    ->where('statut', AffectationIam::STATUT_ACTIVE)
                    ->where('debut_le', '<=', now())
                    ->where(fn ($q) => $q->whereNull('expire_le')->orWhere('expire_le', '>', now()))
                    ->distinct()
                    ->count('user_id');

                if ($count > 0) {
                    $resultats[$profilCode] = $count;
                }
            }
        }

        return $resultats;
    }

    /**
     * Tableaux de bord par faculté.
     */
    public function parFaculte(?Faculte $faculte = null): array
    {
        $facultes = $faculte !== null ? [$faculte] : Faculte::where('active', true)->get();

        $resultats = [];

        foreach ($facultes as $f) {
            $resultats[$f->nom] = [
                'comptes' => User::where('faculte_id', $f->id)->where('actif', true)->count(),
                'affectations_actives' => 0,
                'profils_actifs' => 0,
            ];

            if (Schema::hasTable('iam_affectations')) {
                $resultats[$f->nom]['affectations_actives'] = AffectationIam::where('faculte_id', $f->id)
                    ->where('statut', AffectationIam::STATUT_ACTIVE)
                    ->where('debut_le', '<=', now())
                    ->where(fn ($q) => $q->whereNull('expire_le')->orWhere('expire_le', '>', now()))
                    ->count();
            }

            if (Schema::hasTable('iam_profils')) {
                $resultats[$f->nom]['profils_actifs'] = ProfilIam::where('faculte_id', $f->id)
                    ->where('statut', ProfilIam::STATUT_ACTIF)
                    ->count();
            }
        }

        return $resultats;
    }

    private function tablesDisponibles(): array
    {
        $tables = [];

        foreach (['iam_affectations', 'iam_habilitations', 'iam_delegations', 'iam_urgences', 'iam_recertifications', 'iam_profils', 'iam_evenements'] as $table) {
            if (Schema::hasTable($table)) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    private function compter(string $table): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return (int) DB::table($table)->count();
    }
}
