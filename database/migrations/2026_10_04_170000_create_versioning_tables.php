<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module Versioning — tables du système de versionnage interne.
 *
 * 4 tables : releases, version_components, deployments, audit_versioning.
 * Toutes les FK vers users sont nullable (opérations système/CLI).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Releases ─────────────────────────────────────────────
        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->string('version', 50)->unique();
            $table->string('statut', 30)->default('brouillon');
            $table->string('environnement', 20)->default('local');
            $table->string('app_version', 50);
            $table->string('db_version', 50);
            $table->string('dev_version', 50)->nullable();
            $table->string('prod_version', 50)->nullable();
            $table->string('build_number', 50);
            $table->string('manifest_hash', 64)->nullable();
            $table->string('compatibility_hash', 64)->nullable();
            $table->foreignId('previous_release_id')->nullable()
                ->constrained('releases')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->json('changements')->nullable();
            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('deployed_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('deployed_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->string('lock_key', 64)->nullable();
            $table->timestamps();

            $table->index('statut');
            $table->index('environnement');
            $table->index('build_number');
        });

        // ── Composants de version ────────────────────────────────
        Schema::create('version_components', function (Blueprint $table) {
            $table->id();
            $table->string('composant', 30);       // app, db, dev, prod
            $table->string('version', 50);
            $table->string('environnement', 20)->default('local');
            $table->foreignId('release_id')->nullable()
                ->constrained('releases')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->foreignId('updated_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['composant', 'environnement']);
            $table->index('release_id');
        });

        // ── Déploiements ─────────────────────────────────────────
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('release_id')
                ->constrained('releases')->cascadeOnDelete();
            $table->string('type', 20);              // deploy, rollback
            $table->string('environnement', 20);
            $table->string('statut', 30)->default('en_attente');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duree_secondes')->nullable();
            $table->foreignId('deployed_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('backup_path', 500)->nullable();
            $table->text('erreur')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('release_id');
            $table->index(['environnement', 'statut']);
        });

        // ── Audit versioning (append-only) ───────────────────────
        Schema::create('audit_versioning', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilisateur_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('action', 100);
            $table->string('entite', 50);
            $table->unsignedBigInteger('entite_id')->nullable();
            $table->json('ancienne_valeur')->nullable();
            $table->json('nouvelle_valeur')->nullable();
            $table->string('environnement', 20)->nullable();
            $table->string('adresse_ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('action');
            $table->index(['entite', 'entite_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_versioning');
        Schema::dropIfExists('deployments');
        Schema::dropIfExists('version_components');
        Schema::dropIfExists('releases');
    }
};
