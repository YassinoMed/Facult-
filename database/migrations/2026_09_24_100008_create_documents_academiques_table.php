<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Documents académiques sensibles — stockés hors de public/ (storage/app/private).
     */
    public function up(): void
    {
        Schema::create('documents_academiques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etudiant_id')
                ->constrained('etudiants')
                ->onDelete('cascade');
            $table->foreignId('annee_universitaire_id')
                ->nullable()
                ->constrained('annees_universitaires')
                ->nullOnDelete();
            $table->string('type_document', 50);
            $table->string('nom_original', 255);
            $table->string('chemin', 500); // chemin interne (jamais public)
            $table->string('mime_type', 100);
            $table->unsignedInteger('taille'); // octets
            $table->string('hash_fichier', 64); // SHA-256
            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['etudiant_id', 'annee_universitaire_id'], 'idx_document_etudiant_annee');
            $table->index('hash_fichier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents_academiques');
    }
};
