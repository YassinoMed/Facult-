<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B8 (LOT 1) — corrections d'inscription à QUATRE YEUX : une même
 * rangée de correction peut cibler une inscription (réutilisant le
 * workflow existant dossier_corrections proposee→approuvee→appliquee).
 * Aucune nouvelle table : un seul moteur 4-eyes pour l'inscription
 * ET les champs étudiant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossier_corrections', function (Blueprint $table) {
            $table->foreignId('inscription_academique_id')->nullable()
                ->after('etudiant_id')
                ->constrained('inscriptions_academiques')->nullOnDelete();
            $table->index(['inscription_academique_id', 'statut'], 'idx_corrections_inscription_statut');
        });
    }

    public function down(): void
    {
        Schema::table('dossier_corrections', function (Blueprint $table) {
            $table->dropIndex('idx_corrections_inscription_statut');
            $table->dropConstrainedForeignId('inscription_academique_id');
        });
    }
};
