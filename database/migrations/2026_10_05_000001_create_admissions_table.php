<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap « admission Master — entité dédiée » : acte d'admission
 * traçable (fin de cycle, admission externe), consommé par la
 * progression LMD. Aucune colonne ajoutée aux tables centrales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etudiant_id')->constrained('etudiants')->cascadeOnDelete();
            $table->foreignId('programme_id')->constrained('programmes');
            $table->foreignId('niveau_id')->nullable()->constrained('niveaux');
            $table->foreignId('annee_universitaire_id')->constrained('annees_universitaires');
            $table->enum('decision', ['admis', 'admis_conditionnel', 'refuse']);
            $table->string('motif', 500)->nullable();
            $table->foreignId('decided_by')->constrained('users');
            $table->timestamp('decided_at');
            $table->timestamp('consumed_at')->nullable();
            $table->foreignId('consumed_inscription_id')->nullable()->constrained('inscriptions_academiques');
            $table->timestamps();

            // Recherche du garde de progression : étudiant × année × actif.
            $table->index(['etudiant_id', 'annee_universitaire_id', 'consumed_at'], 'idx_admissions_garde');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admissions');
    }
};
