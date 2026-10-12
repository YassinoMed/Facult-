<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 19 — Gouvernance qualité : persistance des anomalies
 * (historique, acquittement, résolution) + origine des données
 * (lineage : web/import/api/systeme) sur l'audit et les situations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anomalies_qualite', function (Blueprint $table) {
            $table->id();
            $table->string('cle_unique', 120)->unique();
            $table->string('source', 40);
            $table->string('gravite', 20)->default('avertissement');
            $table->string('entite_type', 60)->nullable();
            $table->unsignedBigInteger('entite_id')->nullable();
            $table->string('entite_libelle', 150)->nullable();
            $table->text('message');
            $table->string('lien', 255)->nullable();
            $table->string('action_recommandee', 255)->nullable();
            $table->string('statut', 20)->default('ouverte');
            $table->foreignId('assignee_a')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->foreignId('resolue_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolue_a')->nullable();
            $table->timestamp('derniere_detection_a')->nullable();
            $table->timestamps();
            $table->index(['statut', 'gravite']);
            $table->index(['source', 'statut']);
        });

        Schema::table('historique_academique', function (Blueprint $table) {
            $table->string('source_systeme', 20)->default('web')->after('user_agent');
        });

        Schema::table('situations_etudiant', function (Blueprint $table) {
            $table->string('source_systeme', 20)->default('web')->after('motif');
        });
    }

    public function down(): void
    {
        Schema::table('situations_etudiant', function (Blueprint $table) {
            $table->dropColumn('source_systeme');
        });
        Schema::table('historique_academique', function (Blueprint $table) {
            $table->dropColumn('source_systeme');
        });
        Schema::dropIfExists('anomalies_qualite');
    }
};
