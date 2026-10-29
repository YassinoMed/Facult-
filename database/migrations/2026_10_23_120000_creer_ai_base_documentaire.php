<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODULE AI-ASSISTANT (Lot 6) — base documentaire (RAG v1).
 * Migration ADDITIVE et réversible. Contrôle d'accès appliqué AVANT
 * la restitution des chunks (pas de RLS ici : le filtre est dans
 * BaseDocumentaire selon permissions du demandeur).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_knowledge_sources', function (Blueprint $table) {
            $table->id();
            $table->string('titre', 160);
            $table->string('chemin', 255)->unique();
            $table->string('type', 20); // doc | code | rapport.
            $table->boolean('restreinte')->default(false); // exige ai.documents.restreints.
            $table->string('sha256', 64)->nullable(); // empreinte (anti-réindex inutile).
            $table->string('statut', 12); // actif | retire.
            $table->string('version_indexeur', 12);
            $table->timestamp('indexe_le')->nullable();
            $table->timestamps();

            $table->index('statut');
        });

        Schema::create('ai_knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_id')->index();
            $table->unsignedInteger('ordre');
            $table->text('contenu');
            $table->timestamps();

            $table->index(['source_id', 'ordre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_knowledge_chunks');
        Schema::dropIfExists('ai_knowledge_sources');
    }
};
