<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Crée uniquement le compte Super Administrateur (sans aucune donnée de test).
     */
    public function run(): void
    {
        // Compte Super Admin principal
        $superAdmin = User::firstOrCreate(
            ['email' => 'admin@universite.ac.ma'],
            [
                'name' => 'Super Administrateur',
                'password' => Hash::make('Admin@2026'),
                'actif' => true,
                'email_verified_at' => now(),
            ]
        );
        $superAdmin->definirRole('super_admin');

        // Compte Super Admin alternatif (pratique pour le dev : admin@admin.com)
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
}
