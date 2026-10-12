<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E — Traçabilité référentielle : `etudiant_id` devient nullable
 * pour accueillir les événements hors dossier étudiant (ex. enseignants)
 * dans le journal central existant (AUCUNE 2e table, centre d'audit et
 * export inchangés dans leur contrat). Les lignes étudiantes gardent
 * leur cascade ; les lignes à NULL ne sont liées à aucun dossier.
 * Réversible : le down supprime uniquement les lignes sans dossier
 * (créées par cette fonctionnalité) avant de restaurer NOT NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('historique_academique', function (Blueprint $table) {
            $table->dropForeign(['etudiant_id']);
        });

        Schema::table('historique_academique', function (Blueprint $table) {
            $table->unsignedBigInteger('etudiant_id')->nullable()->change();
            $table->foreign('etudiant_id')->references('id')->on('etudiants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('historique_academique')->whereNull('etudiant_id')->delete();

        Schema::table('historique_academique', function (Blueprint $table) {
            $table->dropForeign(['etudiant_id']);
        });

        Schema::table('historique_academique', function (Blueprint $table) {
            $table->unsignedBigInteger('etudiant_id')->nullable(false)->change();
            $table->foreign('etudiant_id')->references('id')->on('etudiants')->cascadeOnDelete();
        });
    }
};
