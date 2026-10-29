<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * §2.7 — séparation des pouvoirs PAR RÔLE : les trois rôles dédiés du
 * circuit de gouvernance doivent être STOCKABLES.
 *
 * La matrice `Permissions::ROLES` livrait déjà `certificateur_qualite`,
 * `direction_etudes` et `autorite_publication`, mais la colonne MySQL
 * `users.role` restait un ENUM limité aux rôles historiques : toute
 * attribution était tronquée par le serveur (erreur 1265). Cette migration
 * aligne le schéma sur la matrice, sans toucher aux rôles existants.
 *
 * SQLite/PostgreSQL ne sont pas concernés ici : la colonne y est un
 * varchar (le contrôle de valeurs reste applicatif, via
 * `Permissions::roleValide`).
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private array $roles = [
        'super_admin',
        'admin_scolarite',
        'agent_scolarite',
        'consultation',
        'admin_faculte',
        'enseignant',
        'certificateur_qualite',
        'direction_etudes',
        'autorite_publication',
    ];

    /** @var array<int, string> */
    private array $rolesAvant = [
        'super_admin',
        'admin_scolarite',
        'agent_scolarite',
        'consultation',
        'admin_faculte',
        'enseignant',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement($this->definition($this->roles));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Aucune perte silencieuse : un compte encore porteur d'un rôle
        // dédié doit être réaffecté avant le retour arrière.
        $utilises = DB::table('users')
            ->whereIn('role', ['certificateur_qualite', 'direction_etudes', 'autorite_publication'])
            ->exists();

        if ($utilises) {
            throw new RuntimeException(
                'Retour arrière refusé : des comptes portent encore un rôle dédié à la séparation des examens. '
                .'Réaffectez-les avant de rejouer cette migration.'
            );
        }

        DB::statement($this->definition($this->rolesAvant));
    }

    /**
     * @param  array<int, string>  $roles
     */
    private function definition(array $roles): string
    {
        $liste = implode(', ', array_map(fn (string $role): string => "'{$role}'", $roles));

        return "ALTER TABLE users MODIFY COLUMN role ENUM({$liste}) NOT NULL DEFAULT 'consultation'";
    }
};
