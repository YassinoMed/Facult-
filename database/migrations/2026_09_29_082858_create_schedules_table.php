<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module Schedule : un emploi du temps = groupe × année × semestre
 * (semestre académique 1/2 — aucun modèle Semestre créé, cf. rapport).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('annee_universitaire_id')->constrained('annees_universitaires')->restrictOnDelete();
            $table->unsignedTinyInteger('semestre');
            $table->foreignId('groupe_id')->constrained('groups')->cascadeOnDelete();
            $table->string('nom', 150);
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->unique(['groupe_id', 'annee_universitaire_id', 'semestre']);
            $table->index(['annee_universitaire_id', 'semestre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
