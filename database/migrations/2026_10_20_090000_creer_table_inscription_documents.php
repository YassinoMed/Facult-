<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOOP-UNIREGISTRE-DOCUMENTS-REUTILISABLES-01 : association entre un
 * document permanent (`student_documents`, un seul fichier physique) et
 * une inscription académique annuelle.
 *
 * Une ligne par (inscription, type de pièce) : elle porte la décision de
 * réutilisation de l'année et un instantané de la version retenue. Les
 * fichiers et l'historique de validation restent portés par
 * `student_documents` / `student_document_versions` — aucune copie.
 *
 * Migration strictement additive : aucune colonne existante modifiée,
 * aucun document supprimé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inscription_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('inscription_academique_id')
                ->constrained('inscriptions_academiques')
                ->cascadeOnDelete();

            // Nullable + nullOnDelete : la suppression (manuelle, contrôlée)
            // d'un document ne doit jamais casser l'historique annuel ; le
            // snapshot conserve la trace de la décision.
            $table->foreignId('student_document_id')
                ->nullable()
                ->constrained('student_documents')
                ->nullOnDelete();

            $table->foreignId('student_document_version_id')
                ->nullable()
                ->constrained('student_document_versions')
                ->nullOnDelete();

            $table->string('document_type', 60);
            $table->boolean('obligatoire')->default(true);

            // reutilise  : document validé, antérieur à l'inscription
            // associe    : document validé, rattaché après l'inscription
            // en_attente : téléversé mais jamais considéré comme validé
            // rejete / expire / non_conforme / manquant : à fournir
            $table->enum('decision', [
                'reutilise',
                'associe',
                'en_attente',
                'rejete',
                'expire',
                'non_conforme',
                'manquant',
            ])->default('manquant');

            $table->string('motif', 500)->nullable();

            // Instantané non personnel : version, dates de validation et
            // d'expiration, MIME, extension, taille, empreinte.
            $table->json('snapshot')->nullable();

            $table->foreignId('decide_par')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('decide_le')->nullable();

            $table->timestamps();

            $table->unique(
                ['inscription_academique_id', 'document_type'],
                'uniq_inscription_document_type'
            );
            $table->index('student_document_id');
            $table->index('decision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inscription_documents');
    }
};
