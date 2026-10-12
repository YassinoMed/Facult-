<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 17 §17.2 : centre import/export. Persiste chaque étape du
 * workflow (reprise après fermeture navigateur), lignes avec statut et
 * action prévue, snapshots avant/après pour rollback, jobs d'export
 * avec expiration, mappings et modèles réutilisables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('import_jobs')) {
            Schema::create('import_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('entite', 60)->index();
                $table->string('nom_fichier', 255);
                $table->string('hash_fichier', 64)->index();
                $table->string('format', 10);
                $table->string('encodage', 20)->default('UTF-8');
                $table->string('delimiteur', 5)->nullable();
                $table->json('mapping')->nullable();
                $table->json('options')->nullable();
                $table->string('mode', 20)->default('tout_ou_rien')->index();
                $table->string('statut', 20)->default('depose')->index();
                $table->unsignedInteger('total_lignes')->default(0);
                $table->unsignedInteger('lignes_valides')->default(0);
                $table->unsignedInteger('lignes_erreur')->default(0);
                $table->unsignedInteger('lignes_importees')->default(0);
                $table->foreignId('auteur_id')->constrained('users')->cascadeOnDelete();
                $table->timestamp('confirme_le')->nullable();
                $table->timestamps();
                $table->index(['entite', 'statut']);
            });
        }
        if (! Schema::hasTable('import_job_rows')) {
            Schema::create('import_job_rows', function (Blueprint $table) {
                $table->id();
                $table->foreignId('import_job_id')->constrained('import_jobs')->cascadeOnDelete();
                $table->unsignedInteger('numero_ligne');
                $table->json('donnees_brutes');
                $table->json('donnees_normalisees')->nullable();
                $table->string('statut', 20)->default('valid')->index();
                $table->json('erreurs')->nullable();
                $table->json('avertissements')->nullable();
                $table->string('action_prevue', 20)->default('create')->index();
                $table->json('diff_avant_apres')->nullable();
                $table->text('ligne_corrigee')->nullable();
                $table->timestamps();
                $table->unique(['import_job_id', 'numero_ligne']);
                $table->index(['import_job_id', 'statut']);
            });
        }
        if (! Schema::hasTable('import_mapping_templates')) {
            Schema::create('import_mapping_templates', function (Blueprint $table) {
                $table->id();
                $table->string('entite', 60)->index();
                $table->string('nom', 150);
                $table->json('mapping');
                $table->json('transformations')->nullable();
                $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('import_changes')) {
            Schema::create('import_changes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('import_job_id')->constrained('import_jobs')->cascadeOnDelete();
                $table->string('entite', 60);
                $table->string('cle_metier', 255)->index();
                $table->string('action', 20)->index();
                $table->json('avant')->nullable();
                $table->json('apres')->nullable();
                $table->timestamp('annule_le')->nullable();
                $table->timestamps();
                $table->index(['import_job_id', 'action']);
            });
        }
        if (! Schema::hasTable('export_jobs')) {
            Schema::create('export_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('entite', 60)->index();
                $table->json('filtres')->nullable();
                $table->json('colonnes')->nullable();
                $table->string('format', 10);
                $table->string('statut', 20)->default('en_attente')->index();
                $table->string('chemin_fichier', 255)->nullable();
                $table->timestamp('expire_le')->nullable()->index();
                $table->foreignId('auteur_id')->constrained('users')->cascadeOnDelete();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('export_templates')) {
            Schema::create('export_templates', function (Blueprint $table) {
                $table->id();
                $table->string('entite', 60)->index();
                $table->string('nom', 150);
                $table->json('colonnes');
                $table->json('filtres')->nullable();
                $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('export_templates');
        Schema::dropIfExists('export_jobs');
        Schema::dropIfExists('import_changes');
        Schema::dropIfExists('import_mapping_templates');
        Schema::dropIfExists('import_job_rows');
        Schema::dropIfExists('import_jobs');
    }
};
