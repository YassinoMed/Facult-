<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajoute le mot de passe (haché bcrypt) pour l'accès à l'Espace Étudiant.
     * Nullable : les dossiers existants restent valides ; le seeder backfill les comptes de démonstration.
     */
    public function up(): void
    {
        Schema::table('etudiants', function (Blueprint $table) {
            $table->string('password')->nullable()->after('num_inscription');
        });
    }

    public function down(): void
    {
        Schema::table('etudiants', function (Blueprint $table) {
            $table->dropColumn('password');
        });
    }
};
