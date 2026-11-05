<?php

namespace Tests\Concerns;

use App\Models\Faculte;
use App\Models\Iam\AffectationIam;
use App\Models\Iam\DemandeIam;
use App\Models\Iam\HabilitationIam;
use App\Models\Iam\ProfilIam;
use App\Models\User;
use App\Services\Iam\ServiceAffectationsIam;
use App\Services\Iam\ServiceDemandesIam;
use App\Services\Iam\ServiceProfilsIam;
use App\Support\Iam\MoteurAutorisation;

/**
 * Contexte partagé des suites de gouvernance IAM (LOOP GLOBAL IAM/RBAC).
 *
 * Toutes les créations passent par les services réels : les tests ne
 * fabriquent pas un état impossible à produire par l'application.
 */
trait ConstruitContexteIam
{
    protected function creerFaculte(string $code, string $nom = ''): Faculte
    {
        return Faculte::create([
            'nom' => $nom !== '' ? $nom : 'Faculté '.$code,
            'code' => $code,
            'description' => 'Faculté de test IAM',
            'active' => true,
        ]);
    }

    protected function creerSuperAdmin(): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Super Admin IAM',
            'email' => uniqid('iam.super.', true).'@universite.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin');
    }

    protected function creerCompte(string $role = 'agent_scolarite', ?Faculte $faculte = null): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Compte '.$role.' '.uniqid(),
            'email' => uniqid('iam.'.$role.'.', true).'@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $faculte?->id,
        ], $role);
    }

    /**
     * Accorde des permissions additives par habilitation directe. Ce chemin
     * est volontairement distinct des profils : il sert à donner à un acteur
     * non super-admin l'accès à l'écran de gouvernance pour tester les gardes.
     *
     * @param  array<int, string>  $codes
     */
    protected function accorderPermissions(User $utilisateur, array $codes): void
    {
        foreach ($codes as $code) {
            HabilitationIam::query()->create([
                'user_id' => $utilisateur->id,
                'permission_code' => $code,
                'statut' => HabilitationIam::STATUT_ACTIVE,
                'debut_le' => now()->subMinute(),
                'motif' => 'Habilitation de test IAM',
            ]);
        }

        MoteurAutorisation::invalider($utilisateur->id);
    }

    /**
     * @param  array<int, string>  $permissions
     * @param  array<string, mixed>  $attributs
     */
    protected function creerProfil(
        User $createur,
        array $permissions = [],
        array $attributs = []
    ): ProfilIam {
        return app(ServiceProfilsIam::class)->creer(
            code: (string) ($attributs['code'] ?? 'perso_'.uniqid()),
            nom: (string) ($attributs['nom'] ?? 'Rôle personnalisé de test'),
            description: $attributs['description'] ?? 'Rôle créé par la suite de tests IAM.',
            faculte: $attributs['faculte'] ?? null,
            createur: $createur,
            permissionCodes: $permissions,
        );
    }

    /**
     * @param  array<int, string>  $permissions
     * @param  array<string, mixed>  $attributs
     */
    protected function creerProfilActif(
        User $createur,
        array $permissions = [],
        array $attributs = []
    ): ProfilIam {
        $profil = $this->creerProfil($createur, $permissions, $attributs);

        return app(ServiceProfilsIam::class)->valider($profil, $createur);
    }

    protected function affecter(
        User $cible,
        ProfilIam $profil,
        ?User $acteur = null,
        ?string $expireLe = null
    ): AffectationIam {
        return app(ServiceAffectationsIam::class)->affecter(
            utilisateur: $cible,
            profil: $profil,
            faculte: $profil->faculte,
            acteur: $acteur,
            payload: ['motif' => 'Affectation de test IAM'],
            expireLe: $expireLe,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function creerDemande(
        string $type,
        User $initiateur,
        ?User $cible = null,
        ?ProfilIam $profil = null,
        ?Faculte $faculte = null,
        array $payload = [],
        string $motif = 'Demande de test IAM'
    ): DemandeIam {
        return app(ServiceDemandesIam::class)->creerDemande(
            type: $type,
            initiateur: $initiateur,
            cible: $cible,
            profil: $profil,
            faculte: $faculte,
            payload: $payload,
            motif: $motif,
        );
    }
}
