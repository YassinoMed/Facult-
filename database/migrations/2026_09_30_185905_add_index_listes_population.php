<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 (§2.18) : index composites des axes WHERE/ORDER des listes
 * (EXPLAIN : filtres faculté+statut+programme+groupe, jointures tri).
 * Aucun doublon : chaque composite couvre une combinaison non indexée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etudiants', function (Blueprint $table) {
            $table->index(['faculte_id', 'statut'], 'idx_etudiants_faculte_statut');
            $table->index(['faculte_id', 'programme_id', 'niveau'], 'idx_etudiants_faculte_prog_niveau');
            $table->index(['groupe_id', 'statut'], 'idx_etudiants_groupe_statut');
        });

        Schema::table('affectations_groupe', function (Blueprint $table) {
            $table->index(['annee_universitaire_id', 'statut'], 'idx_affectations_annee_statut');
            $table->index(['sous_groupe_id', 'statut'], 'idx_affectations_ssgroupe_statut');
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->index(['faculte_id', 'programme_id'], 'idx_groups_faculte_programme');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropIndex('idx_groups_faculte_programme');
        });

        Schema::table('affectations_groupe', function (Blueprint $table) {
            $table->dropIndex('idx_affectations_annee_statut');
            $table->dropIndex('idx_affectations_ssgroupe_statut');
        });

        Schema::table('etudiants', function (Blueprint $table) {
            $table->dropIndex('idx_etudiants_faculte_statut');
            $table->dropIndex('idx_etudiants_faculte_prog_niveau');
            $table->dropIndex('idx_etudiants_groupe_statut');
        });
    }
};
