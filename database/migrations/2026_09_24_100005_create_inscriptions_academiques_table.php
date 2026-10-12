<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inscription académique d'un étudiant pour une année universitaire donnée.
     * Unicité : un étudiant ne peut avoir qu'une inscription par année universitaire.
     */
    public function up(): void
    {
        Schema::create('inscriptions_academiques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etudiant_id')
                ->constrained('etudiants')
                ->onDelete('cascade');
            $table->foreignId('annee_universitaire_id')
                ->constrained('annees_universitaires')
                ->onDelete('restrict');
            $table->string('niveau', 10);
            $table->string('filiere', 150)->nullable();
            $table->string('groupe', 20)->nullable();
            $table->enum('statut', ['en_cours', 'valide', 'ajourne', 'abandon'])->default('en_cours');
            $table->decimal('moyenne_annuelle', 5, 2)->nullable();
            $table->unsignedSmallInteger('credits_obtenus')->default(0);
            $table->unsignedSmallInteger('credits_total')->default(0);
            $table->string('decision', 100)->nullable();
            $table->text('observation')->nullable();
            $table->timestamps();

            $table->unique(['etudiant_id', 'annee_universitaire_id'], 'uniq_inscription_etudiant_annee');
            $table->index('annee_universitaire_id');
            $table->index(['niveau', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inscriptions_academiques');
    }
};
