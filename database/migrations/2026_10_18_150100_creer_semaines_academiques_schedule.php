<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('academic_weeks')) {
            Schema::create('academic_weeks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('annee_universitaire_id')->constrained('annees_universitaires')->cascadeOnDelete();
                $table->unsignedTinyInteger('semestre')->default(1);
                $table->foreignId('semestre_lmd_id')->nullable()->constrained('semestres_lmd')->nullOnDelete();
                $table->unsignedSmallInteger('numero_semaine'); // Numéro relatif au semestre (1..16) ou absolu
                $table->string('code', 30); // ex: S01, S02, S_REV, S_EXAM
                $table->string('nom', 120); // ex: Semaine 1 — Cours
                $table->date('date_debut');
                $table->date('date_fin');
                $table->string('type', 30)->default('cours'); // cours, vacances, revision, examens, rattrapage
                $table->string('parite', 20)->default('toutes'); // toutes, paire, impaire
                $table->boolean('actif')->default(true);
                $table->timestamps();

                $table->index(['annee_universitaire_id', 'semestre', 'numero_semaine'], 'idx_academic_weeks_annee_sem_num');
                $table->index(['date_debut', 'date_fin'], 'idx_academic_weeks_dates');
                $table->index(['type', 'parite'], 'idx_academic_weeks_type_parite');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('academic_weeks');
    }
};
