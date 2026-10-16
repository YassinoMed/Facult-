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
        Schema::create('participation_scrutins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etudiant_id')->constrained('etudiants')->onDelete('cascade');
            $table->foreignId('scrutin_id')->constrained()->onDelete('cascade');
            $table->string('jeton_unicite', 64)->unique(); // Hash pseudonymisé pour anti-double vote
            $table->enum('etat', ['valide', 'rejeté', 'annulé'])->default('valide');
            $table->json('critères_version')->nullable(); // Version des critères d'éligibilité utilisés au moment du vote
            $table->timestamps();

            // Indexations et contraintes d'unicité
            $table->index(['etudiant_id', 'scrutin_id']);
            $table->unique(['etudiant_id', 'scrutin_id']);
            $table->index('jeton_unicite');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('participation_scrutins');
    }
};
