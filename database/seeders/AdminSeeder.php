<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\DemoData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Crée le compte Super Administrateur initial (sans aucune donnée de test).
 *
 * LOOP-SECURITY-REMEDIATION-01 : hors local/testing, le mot de passe est
 * EXIGÉ via `ADMIN_INITIAL_PASSWORD` — aucun mot de passe par défaut connu
 * n'est jamais posé. Le second compte de démonstration (admin@admin.com)
 * n'est créé qu'en démo explicitement autorisée.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $motDePasse = $this->motDePasseInitial();

        $superAdmin = User::firstOrCreate(
            ['email' => 'admin@universite.ac.ma'],
            [
                'name' => 'Super Administrateur',
                'password' => Hash::make($motDePasse),
                'actif' => true,
                'email_verified_at' => now(),
            ]
        );
        $superAdmin->definirRole('super_admin');

        // Compte de démonstration secondaire (admin@admin.com / admin123) :
        // jamais créé hors contexte de démonstration.
        if (! DemoData::autorisee()) {
            return;
        }

        if (! app()->environment('local', 'testing')) {
            $this->command?->warn('SEED_DEMO_DATA=true : compte de démonstration admin@admin.com créé — jamais pour une production réelle.');
        }

        $adminSimple = User::firstOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('admin123'),
                'actif' => true,
                'email_verified_at' => now(),
            ]
        );
        $adminSimple->definirRole('super_admin');
    }

    /**
     * Mot de passe du compte super-admin initial :
     * - hors démo : `ADMIN_INITIAL_PASSWORD` OBLIGATOIRE (12 caractères min.) ;
     * - en démo (local/testing ou SEED_DEMO_DATA=true) : repli sur le mot de
     *   passe de démonstration historique.
     */
    private function motDePasseInitial(): string
    {
        $motDePasse = (string) config('security.admin_initial_password', '');

        if ($motDePasse !== '') {
            if (strlen($motDePasse) < 12) {
                throw new RuntimeException(
                    'ADMIN_INITIAL_PASSWORD trop court (12 caractères minimum) : '.self::class.' refuse un mot de passe faible.'
                );
            }

            return $motDePasse;
        }

        if (! DemoData::autorisee()) {
            throw new RuntimeException(
                'ADMIN_INITIAL_PASSWORD est obligatoire hors environnement local/testing : '.self::class.
                ' ne pose jamais de mot de passe par défaut connu en production/staging. '.
                'Définissez la variable d’environnement le temps du seeding, puis retirez-la.'
            );
        }

        return 'Admin@2026';
    }
}
