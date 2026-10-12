<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16 : complète les tables du centre documentaire manquantes
 * suite à un déploiement partiel (DDL MySQL non transactionnel).
 * Idempotente : ne crée que ce qui manque.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_template_versions')) {
            Schema::create('document_template_versions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('document_template_id')->constrained('document_templates')->cascadeOnDelete();
                $table->unsignedInteger('version');
                $table->text('contenu');
                $table->json('schema_variables')->nullable();
                $table->string('gabarit', 255)->nullable();
                $table->string('cree_par', 150)->nullable();
                $table->timestamps();
                $table->unique(['document_template_id', 'version']);
            });
        }

        if (! Schema::hasTable('document_signatories')) {
            Schema::create('document_signatories', function (Blueprint $table) {
                $table->id();
                $table->string('nom', 150);
                $table->string('titre', 150);
                $table->string('image_path', 255)->nullable();
                $table->date('valide_du')->nullable();
                $table->date('valide_au')->nullable();
                $table->boolean('actif')->default(true)->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('document_numbering_sequences')) {
            Schema::create('document_numbering_sequences', function (Blueprint $table) {
                $table->id();
                $table->string('type', 60);
                $table->string('annee', 4);
                $table->string('perimetre', 60)->default('global');
                $table->unsignedBigInteger('prochain_numero')->default(1);
                $table->string('format', 60)->default('{PREFIXE}-{ANNEE}-{SEQ:6}');
                $table->timestamps();
                $table->unique(['type', 'annee', 'perimetre']);
            });
        }

        if (! Schema::hasTable('document_batches')) {
            Schema::create('document_batches', function (Blueprint $table) {
                $table->id();
                $table->string('reference', 40)->unique();
                $table->string('type', 60)->index();
                $table->json('criteres')->nullable();
                $table->string('statut', 20)->default('en_cours')->index();
                $table->unsignedInteger('total')->default(0);
                $table->unsignedInteger('reussis')->default(0);
                $table->unsignedInteger('echoues')->default(0);
                $table->unsignedInteger('ignores')->default(0);
                $table->json('rapport')->nullable();
                $table->string('archive_path', 255)->nullable();
                $table->timestamp('archive_expire_le')->nullable();
                $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('document_events')) {
            Schema::create('document_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('document_id')->constrained('generated_documents')->cascadeOnDelete();
                $table->string('evenement', 40)->index();
                $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->index(['document_id', 'evenement']);
            });
        }

        if (Schema::hasTable('document_templates') && ! Schema::hasColumn('document_templates', 'version_courante_id')) {
            Schema::table('document_templates', function (Blueprint $table) {
                $table->unsignedBigInteger('version_courante_id')->nullable()->after('faculte_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_events');
        Schema::dropIfExists('document_batches');
        Schema::dropIfExists('document_numbering_sequences');
        Schema::dropIfExists('document_signatories');
        Schema::dropIfExists('document_template_versions');
    }
};
