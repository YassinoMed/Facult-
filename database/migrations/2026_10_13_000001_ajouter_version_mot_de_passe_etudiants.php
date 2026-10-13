<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AUTH-01 (audit auth 12/10 — correctif 13/10) : version du mot de
     * passe étudiant. Les sessions étudiant ne sont pas rattachables par
     * `user_id` (garde serveur `etudiant_auth_id` dans le payload) — la
     * version posée en session à la connexion et re-vérifiée par
     * `EnsureStudent` permet d'invalider immédiatement les sessions
     * ouvertes (cookie volé compris) lors d'une réinitialisation.
     * Additive, réversible, valeur 0 = rétrocompatible (sessions
     * existantes sans version restent valides).
     */
    public function up(): void
    {
        Schema::table('etudiants', function (Blueprint $table) {
            $table->unsignedInteger('mot_de_passe_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('etudiants', function (Blueprint $table) {
            $table->dropColumn('mot_de_passe_version');
        });
    }
};
