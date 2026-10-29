<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * LOOP-UNIREGISTRE-PORTAILS-ENTERPRISE-01 — LOT 1.
     *
     * Version du mot de passe enseignant, miroir de
     * `etudiants.mot_de_passe_version` : les sessions enseignant ne sont
     * pas rattachables par `user_id`, la version posée en session à la
     * connexion et vérifiée par `EnsureTeacher` permet d'invalider les
     * sessions ouvertes lors d'un changement/réinitialisation.
     *
     * Additive, réversible, valeur 0 = rétrocompatible (sessions
     * existantes sans version restent valides).
     */
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->unsignedInteger('mot_de_passe_version')->default(0)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropColumn('mot_de_passe_version');
        });
    }
};
