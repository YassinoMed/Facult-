<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — Calendrier académique (§41) : événementiel transversal
 * (rentrée, vacances, deadlines, périodes). Les cours restent dans
 * Schedule, les examens dans Evaluation : l'agenda les agrège en
 * lecture seule, sans les dupliquer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendrier_evenements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('annee_universitaire_id')->constrained('annees_universitaires')->restrictOnDelete();
            $table->string('titre', 150);
            $table->string('type', 30)->default('evenement');
            $table->date('date_debut');
            $table->date('date_fin')->nullable();
            $table->time('heure')->nullable();
            $table->text('description')->nullable();
            $table->string('lieu', 150)->nullable();
            $table->string('audience', 20)->default('tous');
            $table->foreignId('faculte_id')->nullable()->constrained('facultes')->restrictOnDelete();
            $table->foreignId('programme_id')->nullable()->constrained('programmes')->restrictOnDelete();
            $table->foreignId('groupe_id')->nullable()->constrained('groups')->restrictOnDelete();
            $table->string('statut', 20)->default('publie');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['annee_universitaire_id', 'date_debut']);
            $table->index(['audience', 'faculte_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendrier_evenements');
    }
};
