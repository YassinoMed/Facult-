<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espace Enseignant (Phase 2) : mot de passe de connexion propre à
 * l'enseignant (miroir `etudiants.password`, session dédiée
 * `enseignant_auth_id`, jamais de compte `users` administratif).
 * NULL = compte non activé (connexion refusée, message générique).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->string('password')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropColumn('password');
        });
    }
};
