<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refonte affectation automatique : simulations persistées (technique,
 * jamais d'écriture métier) + items proposés + confirmations par groupe.
 * Statuts : brouillon → simulee → partiellement_confirmee → confirmee |
 * expiree | annulee. Version + hash d'obsolescence (§28).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_simulations', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('statut', 30)->default('brouillon')->index();
            $table->foreignId('annee_universitaire_id')->constrained('annees_universitaires')->restrictOnDelete();
            $table->json('parametres');
            $table->string('hash_entrees', 64);
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('candidats')->default(0);
            $table->unsignedInteger('eligibles')->default(0);
            $table->unsignedInteger('affectes')->default(0);
            $table->unsignedInteger('non_affectes')->default(0);
            $table->unsignedInteger('conflits')->default(0);
            $table->json('metriques')->nullable();
            $table->json('groupes_confirmes')->nullable();
            $table->foreignId('auteur_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->index(['statut', 'annee_universitaire_id']);
        });

        Schema::create('assignment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('simulation_id')->constrained('assignment_simulations')->cascadeOnDelete();
            $table->foreignId('etudiant_id')->constrained('etudiants')->cascadeOnDelete();
            $table->foreignId('groupe_propose_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->json('alternatives')->nullable();
            $table->json('contraintes_validees')->nullable();
            $table->json('conflits')->nullable();
            $table->string('raison', 500)->nullable();
            $table->string('statut', 30)->default('propose')->index();
            $table->timestamps();
            $table->unique(['simulation_id', 'etudiant_id']);
            $table->index(['simulation_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_items');
        Schema::dropIfExists('assignment_simulations');
    }
};
