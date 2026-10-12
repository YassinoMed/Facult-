<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notes académiques critiques.
     * Unicité : une seule note par (semestre, matière, session).
     * locked_at : note verrouillée, modifiable uniquement par un rôle supérieur.
     */
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etudiant_id')
                ->constrained('etudiants')
                ->onDelete('cascade');
            $table->foreignId('inscription_academique_id')
                ->constrained('inscriptions_academiques')
                ->onDelete('cascade');
            $table->foreignId('semestre_id')
                ->constrained('semestres')
                ->onDelete('cascade');
            $table->foreignId('matiere_id')
                ->constrained('matieres')
                ->onDelete('restrict');
            $table->decimal('note', 5, 2);
            $table->decimal('coefficient', 4, 2);
            $table->unsignedSmallInteger('credits');
            $table->enum('session', ['normale', 'rattrapage'])->default('normale');
            $table->text('observation')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->unique(['semestre_id', 'matiere_id', 'session'], 'uniq_note_semestre_matiere_session');
            $table->index(['etudiant_id', 'inscription_academique_id'], 'idx_note_etudiant_inscription');
            $table->index('matiere_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
