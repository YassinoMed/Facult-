<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phases 7-9 — Tâches, récents, vues sauvegardées.
 * `tasks` : centre transverse idempotent (cle_unique = source).
 * Aucun doublon : les anomalies SLA/assiduité/qualité existantes
 * deviennent des émetteurs, pas des systèmes parallèles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('titre', 150);
            $table->text('description')->nullable();
            $table->string('source', 30);
            $table->string('cle_unique', 120)->unique();
            $table->string('statut', 20)->default('pending');
            $table->string('priorite', 20)->default('normal');
            $table->timestamp('echeance_a')->nullable();
            $table->string('lien', 255)->nullable();
            $table->json('data')->nullable();
            $table->foreignId('faculte_id')->nullable()->constrained('facultes')->restrictOnDelete();
            $table->foreignId('assigne_a')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('terminee_a')->nullable();
            $table->timestamps();
            $table->index(['statut', 'priorite']);
            $table->index(['assigne_a', 'statut']);
            $table->index(['faculte_id', 'statut']);
        });

        Schema::create('task_evenements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('action', 30);
            $table->text('commentaire')->nullable();
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index('task_id');
        });

        Schema::create('elements_recents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('reference', 120);
            $table->string('titre', 150);
            $table->string('lien', 255);
            $table->timestamps();
            $table->unique(['user_id', 'type', 'reference']);
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('vues_sauvegardees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('tableau', 60);
            $table->string('nom', 80);
            $table->json('filtres');
            $table->boolean('par_defaut')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'tableau']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vues_sauvegardees');
        Schema::dropIfExists('elements_recents');
        Schema::dropIfExists('task_evenements');
        Schema::dropIfExists('tasks');
    }
};
