<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajoute le rôle RBAC (back-office scolarité) à la table users standard de Laravel.
     *
     * Rôles :
     * - super_admin       : administration globale (toutes facultés) ;
     * - admin_scolarite   : scolarité centrale (global) ;
     * - agent_scolarite   : agent scolarité centrale (global) ;
     * - consultation      : consultation seule (global) ;
     * - admin_faculte     : administrateur RATTACHÉ à une faculté (users.faculte_id)
     *                       — accès strictement limité à sa faculté (§6 isolation).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['super_admin', 'admin_scolarite', 'agent_scolarite', 'consultation', 'admin_faculte'])
                ->default('consultation')
                ->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
