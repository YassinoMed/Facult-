<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase F (§7-11, §42) : confidentialité graduée + dossier sensible.
 *
 * - Colonne `niveau_confidentialite` (standard/restreint/confidentiel) sur
 *   les deux GED : dimension absente partout, aucune duplication.
 * - Table `student_sensitive_records` : SEULE nouveauté. Justification :
 *   incident/commission/décision/aménagement n'existent NULLE PART
 *   (ni SituationEtudiant ni GED ni délibérations) ; fichiers restent GED
 *   (lien source+id, jamais de copie) ; minimum-necessary (AUCUNE colonne
 *   diagnostic/traitement) ; soft delete (jamais de destruction silencieuse) ;
 *   rétention extensible SANS durées hardcodées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents_academiques', function (Blueprint $table) {
            $table->enum('niveau_confidentialite', ['standard', 'restreint', 'confidentiel'])
                ->default('standard')->after('type_document');
            $table->index('niveau_confidentialite', 'idx_docacad_conf');
        });

        Schema::table('student_documents', function (Blueprint $table) {
            $table->enum('niveau_confidentialite', ['standard', 'restreint', 'confidentiel'])
                ->default('standard')->after('category');
            $table->index(['student_dossier_id', 'niveau_confidentialite'], 'idx_studoc_dossier_conf');
        });

        Schema::create('student_sensitive_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('etudiant_id')->constrained('etudiants')->cascadeOnDelete();
            $table->enum('type', ['medical', 'disciplinaire', 'situation_particuliere', 'administratif_restreint']);
            $table->string('titre', 150);
            $table->text('description')->nullable();
            $table->enum('statut', ['ouvert', 'en_cours', 'cloture', 'archive'])->default('ouvert');
            $table->enum('niveau', ['restreint', 'confidentiel'])->default('confidentiel');
            $table->date('date_debut')->nullable();
            $table->date('date_fin')->nullable();
            $table->enum('document_source', ['ged', 'dossier', 'demande', 'justificatif'])->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('retention_policy', 100)->nullable();
            $table->date('retention_until')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['etudiant_id', 'type'], 'idx_sens_etudiant_type');
            $table->index('niveau', 'idx_sens_niveau');
            $table->index('statut', 'idx_sens_statut');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_sensitive_records');

        Schema::table('student_documents', function (Blueprint $table) {
            $table->dropIndex('idx_studoc_dossier_conf');
            $table->dropColumn('niveau_confidentialite');
        });

        Schema::table('documents_academiques', function (Blueprint $table) {
            $table->dropIndex('idx_docacad_conf');
            $table->dropColumn('niveau_confidentialite');
        });
    }
};
