<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16 §16.2 : centre documentaire. Étend le registre existant
 * `generated_documents` (aucun second registre) avec les tables
 * satellites : modèles versionnés, signataires, séquences de
 * numérotation, lots, journal d'événements, vérifications publiques.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('type', 60)->index();
            $table->string('nom', 150);
            $table->string('locale', 10)->default('fr');
            $table->string('moteur', 20)->default('blade');
            $table->string('format', 10)->default('A4');
            $table->string('orientation', 15)->default('portrait');
            $table->string('statut', 20)->default('brouillon')->index();
            $table->date('valide_du')->nullable();
            $table->date('valide_au')->nullable();
            $table->unsignedBigInteger('faculte_id')->nullable()->index();
            $table->unsignedBigInteger('version_courante_id')->nullable();
            $table->timestamps();
        });

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

        Schema::create('document_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('generated_documents')->cascadeOnDelete();
            $table->string('evenement', 40)->index();
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['document_id', 'evenement']);
        });

        Schema::create('document_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->nullable()->constrained('generated_documents')->nullOnDelete();
            $table->string('code', 64)->index();
            $table->string('ip_hachee', 64);
            $table->string('resultat', 20)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_verifications');
        Schema::dropIfExists('document_events');
        Schema::dropIfExists('document_batches');
        Schema::dropIfExists('document_numbering_sequences');
        Schema::dropIfExists('document_signatories');
        Schema::dropIfExists('document_template_versions');
        Schema::dropIfExists('document_templates');
    }
};
