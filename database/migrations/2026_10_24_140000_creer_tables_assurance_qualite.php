<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 16 — Assurance qualité et accréditation.
 *
 * Tables ADDITIVES et RÉVERSIBLES du module uniquement :
 * - `referentiels_accreditation` : identité d'un référentiel (organisme
 *   SAISI, jamais seedé — inventer des critères est interdit).
 * - `versions_referentiel_accreditation` : catalogue VERSIONNÉ ; une
 *   version FIGÉE à l'approbation (plus aucune modification des critères).
 * - `criteres_accreditation` : critères portés par une version.
 * - `constats_accreditation` : évaluation humaine par (critère, périmètre) ;
 *   JAMAIS de conformité par défaut — un critère sans constat n'existe pas
 *   dans un rapport.
 * - `preuves_constat_accreditation` : liens typés vers des artefacts RÉELS
 *   (document du coffre, anomalie qualité, entrée de journal).
 * - `plans_action_accreditation` : mesures correctives reliées au constat.
 * - `rapports_accreditation` : workflow brouillon → soumis → approuvé/rejeté ;
 *   totaux FIGÉS à la soumission, approbateur ≠ soumetteur.
 *
 * Aucune colonne ajoutée aux tables centrales (règle modules du dépôt).
 * Contrainte UNIQUE portable avec NULL : colonne `perimetre` textuelle
 * ('global' ou 'faculte:{id}') — SQLite traite les NULL comme distincts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referentiels_accreditation', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('nom', 150);
            $table->string('organisme', 150); // saisi par l'administration, jamais seedé
            $table->text('description')->nullable();
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('versions_referentiel_accreditation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referentiel_id')->constrained('referentiels_accreditation')->restrictOnDelete();
            $table->unsignedSmallInteger('numero');
            $table->string('statut', 20)->default('brouillon'); // brouillon | approuvee | archivee
            $table->text('motif_figage')->nullable(); // trace du figage à l'approbation
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approuve_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approuve_le')->nullable();
            $table->timestamps();

            $table->unique(['referentiel_id', 'numero']);
        });

        Schema::create('criteres_accreditation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_referentiel_id')->constrained('versions_referentiel_accreditation')->restrictOnDelete();
            $table->string('code', 60);
            $table->string('libelle', 200);
            $table->text('description')->nullable();
            $table->foreignId('faculte_id')->nullable()->constrained('facultes')->nullOnDelete(); // null = portée globale
            $table->timestamps();

            $table->unique(['version_referentiel_id', 'code']);
        });

        Schema::create('constats_accreditation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('critere_id')->constrained('criteres_accreditation')->restrictOnDelete();
            $table->string('perimetre', 30); // 'global' | 'faculte:{id}' — portable avec NULL
            $table->foreignId('faculte_id')->nullable()->constrained('facultes')->nullOnDelete();
            $table->string('evaluation', 30); // non_evalue | conforme | partiellement_conforme | non_conforme
            $table->text('commentaire')->nullable();
            $table->foreignId('evaluateur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('evalue_le')->nullable();
            $table->timestamps();

            $table->unique(['critere_id', 'perimetre']);
        });

        Schema::create('preuves_constat_accreditation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('constat_id')->constrained('constats_accreditation')->restrictOnDelete();
            $table->string('type', 30); // document | anomalie | journal
            $table->unsignedInteger('cible_id'); // id de l'artefact RÉEL (existence vérifiée)
            $table->string('cible_libelle', 250); // copie datée au moment de l'ajout
            $table->foreignId('ajoute_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['constat_id', 'type', 'cible_id']);
        });

        Schema::create('plans_action_accreditation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('constat_id')->constrained('constats_accreditation')->restrictOnDelete();
            $table->text('mesure');
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('echeance')->nullable();
            $table->string('statut', 20)->default('ouvert'); // ouvert | termine
            $table->foreignId('cloture_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cloture_le')->nullable();
            $table->timestamps();
        });

        Schema::create('rapports_accreditation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_referentiel_id')->constrained('versions_referentiel_accreditation')->restrictOnDelete();
            $table->string('perimetre', 30); // 'global' | 'faculte:{id}' — portable avec NULL
            $table->foreignId('faculte_id')->nullable()->constrained('facultes')->nullOnDelete();
            $table->string('statut', 20)->default('brouillon'); // brouillon | soumis | approuve | rejete
            $table->foreignId('soumis_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('soumis_le')->nullable();
            $table->foreignId('approuve_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approuve_le')->nullable();
            $table->text('commentaire_approbation')->nullable();
            $table->text('motif_rejet')->nullable();
            // Totaux FIGÉS à la soumission (dénormalisés intentionnellement).
            $table->unsignedInteger('total_criteres')->default(0);
            $table->unsignedInteger('total_conformes')->default(0);
            $table->unsignedInteger('total_partiels')->default(0);
            $table->unsignedInteger('total_non_conformes')->default(0);
            $table->unsignedInteger('total_non_evalues')->default(0);
            $table->timestamps();

            $table->unique(['version_referentiel_id', 'perimetre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rapports_accreditation');
        Schema::dropIfExists('plans_action_accreditation');
        Schema::dropIfExists('preuves_constat_accreditation');
        Schema::dropIfExists('constats_accreditation');
        Schema::dropIfExists('criteres_accreditation');
        Schema::dropIfExists('versions_referentiel_accreditation');
        Schema::dropIfExists('referentiels_accreditation');
    }
};
