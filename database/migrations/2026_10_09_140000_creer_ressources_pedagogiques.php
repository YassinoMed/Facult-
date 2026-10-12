<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOOP 06/14 — Supports de cours (ressources pédagogiques).
 *
 * Table dédiée (zéro colonne centrale, zéro duplication) : un support est
 * publié par un enseignant pour une ECUE (matiere_id) et un groupe
 * ciblé (groupe_id NULL = tous les groupes). Stockage privé, cycle
 * brouillon → publié → archivé, transitions serveur uniquement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ressources_pedagogiques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->foreignId('matiere_id')->constrained('matieres')->cascadeOnDelete();
            $table->foreignId('groupe_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->string('titre', 200);
            $table->text('description')->nullable();
            $table->string('nom_fichier', 255);
            $table->string('chemin', 500);
            $table->string('mime', 120);
            $table->unsignedBigInteger('taille');
            $table->string('statut', 20)->default('brouillon');
            $table->timestamps();

            $table->index(['matiere_id', 'statut']);
            $table->index(['teacher_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ressources_pedagogiques');
    }
};
