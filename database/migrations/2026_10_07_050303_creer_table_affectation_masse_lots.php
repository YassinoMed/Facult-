<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOOP P2 — lots d'affectation de masse par tranches : ordre de
 * travail persistant (sélections + compteurs + erreurs) pour
 * exécution synchrone courte ou Job avec progression et reprise.
 * Le lot orchestre, `MassAssignmentService::confirmer()` décide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affectation_masse_lots', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('annee_universitaire_id')->constrained('annees_universitaires')->restrictOnDelete();
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('statut', ['en_attente', 'en_cours', 'termine', 'echoue', 'annule'])->default('en_attente');
            $table->json('selections');
            $table->string('motif', 255)->nullable();
            $table->boolean('force')->default(false);
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('traites')->default(0);
            $table->unsignedInteger('affectes')->default(0);
            $table->unsignedInteger('ignores')->default(0);
            $table->json('traites_ids')->nullable();
            $table->json('erreurs')->nullable();
            $table->string('message', 500)->nullable();
            $table->timestamps();
            $table->index(['statut', 'annee_universitaire_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affectation_masse_lots');
    }
};
