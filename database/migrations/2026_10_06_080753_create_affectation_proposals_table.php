<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOOP MATURE — Propositions d'affectation (mode semi-automatique & automatique) :
 * Persistance des propositions calculées par AffectationEngine avec scoring /100,
 * niveau de certitude, justifications transparentes, statut de validation et traçabilité.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affectation_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('etudiants')->cascadeOnDelete();
            $table->foreignId('inscription_id')->nullable()->constrained('inscriptions_academiques')->nullOnDelete();
            $table->foreignId('annee_universitaire_id')->constrained('annees_universitaires')->restrictOnDelete();
            $table->foreignId('proposed_group_id')->constrained('groups')->cascadeOnDelete();
            $table->string('mode', 30)->default('semi_automatique')->index(); // automatique, semi_automatique
            $table->unsignedTinyInteger('score')->default(0); // Score /100
            $table->string('certitude', 20)->default('moyenne')->index(); // elevee, moyenne, faible
            $table->string('statut', 30)->default('en_attente')->index(); // en_attente, acceptee, rejetee, modifiee, expiree
            $table->json('reasons')->nullable(); // Justifications, critères validés, ventilation du score
            $table->json('metadata')->nullable(); // Alternatives, charge, flexibilité, source
            $table->foreignId('valide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('valide_le')->nullable();
            $table->text('motif_refus')->nullable();
            $table->timestamps();

            $table->index(['annee_universitaire_id', 'statut']);
            $table->index(['student_id', 'annee_universitaire_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affectation_proposals');
    }
};
