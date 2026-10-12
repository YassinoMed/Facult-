<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — Annonces (§39-40) : publication ciblée, planifiée,
 * accusés de lecture. Diffusion via NotificationService (batch).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annonces', function (Blueprint $table) {
            $table->id();
            $table->string('titre', 150);
            $table->text('contenu');
            $table->string('type', 30)->default('information');
            $table->string('statut', 20)->default('draft');
            $table->unsignedTinyInteger('priorite')->default(0);
            $table->boolean('epinglee')->default(false);
            $table->timestamp('publiee_a')->nullable();
            $table->timestamp('expire_a')->nullable();
            $table->string('lien', 255)->nullable();
            // Ciblage : NULL = tous. Jamais de PII dans ces colonnes.
            $table->foreignId('faculte_id')->nullable()->constrained('facultes')->restrictOnDelete();
            $table->foreignId('programme_id')->nullable()->constrained('programmes')->restrictOnDelete();
            $table->foreignId('groupe_id')->nullable()->constrained('groups')->restrictOnDelete();
            $table->string('audience', 20)->default('tous');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['statut', 'publiee_a']);
            $table->index(['audience', 'faculte_id']);
        });

        Schema::create('annonce_lectures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('annonce_id')->constrained('annonces')->cascadeOnDelete();
            $table->foreignId('etudiant_id')->nullable()->constrained('etudiants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->timestamp('lue_a')->nullable();
            $table->timestamps();
            $table->unique(['annonce_id', 'etudiant_id']);
            $table->unique(['annonce_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annonce_lectures');
        Schema::dropIfExists('annonces');
    }
};
