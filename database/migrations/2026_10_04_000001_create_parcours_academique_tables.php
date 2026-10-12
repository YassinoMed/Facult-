<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module Parcours académique avancé LMD : tables dédiées uniquement.
 * Aucune colonne centrale modifiée (non-duplication, ADDING-A-MODULE).
 * Simulations = append-only, jamais d'écriture métier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parcours_regles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80);
            $table->string('libelle', 200);
            $table->unsignedInteger('version')->default(1);
            $table->boolean('actif')->default(true);
            $table->foreignId('annee_universitaire_id')->nullable()->constrained('annees_universitaires')->nullOnDelete();
            $table->foreignId('programme_id')->nullable()->constrained('programmes')->nullOnDelete();
            $table->json('criteres');
            $table->timestamps();
            $table->unique(['code', 'version']);
            $table->index(['code', 'actif']);
        });

        Schema::create('parcours_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etudiant_id')->constrained('etudiants')->cascadeOnDelete();
            $table->foreignId('inscription_source_id')->nullable()->constrained('inscriptions_academiques')->nullOnDelete();
            $table->string('objectif', 200);
            $table->json('contenu');
            $table->string('statut', 20)->default('brouillon');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['etudiant_id', 'statut']);
        });

        Schema::create('parcours_simulations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etudiant_id')->constrained('etudiants')->cascadeOnDelete();
            $table->foreignId('inscription_source_id')->nullable()->constrained('inscriptions_academiques')->nullOnDelete();
            $table->foreignId('annee_universitaire_id')->nullable()->constrained('annees_universitaires')->nullOnDelete();
            $table->string('scenario', 60);
            $table->json('hypotheses')->nullable();
            $table->json('resultat');
            $table->string('regle_reference', 200)->nullable();
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['etudiant_id', 'scenario']);
        });

        Schema::create('parcours_equivalences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etudiant_id')->constrained('etudiants')->cascadeOnDelete();
            $table->string('origine_libelle', 200);
            $table->unsignedInteger('origine_credits')->default(0);
            $table->foreignId('cible_matiere_id')->nullable()->constrained('matieres')->nullOnDelete();
            $table->foreignId('cible_ue_id')->nullable()->constrained('unites_enseignement')->nullOnDelete();
            $table->string('cible_libelle', 200);
            $table->string('etablissement', 200)->nullable();
            $table->string('decision', 20)->nullable();
            $table->text('justification')->nullable();
            $table->foreignId('decide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decide_le')->nullable();
            $table->string('statut', 20)->default('proposee');
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['etudiant_id', 'statut']);
        });

        Schema::create('parcours_dispenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etudiant_id')->constrained('etudiants')->cascadeOnDelete();
            $table->foreignId('matiere_id')->nullable()->constrained('matieres')->nullOnDelete();
            $table->foreignId('ue_id')->nullable()->constrained('unites_enseignement')->nullOnDelete();
            $table->string('cible_libelle', 200);
            $table->text('motif');
            $table->string('decision', 20)->nullable();
            $table->string('responsable', 150)->nullable();
            $table->date('date_debut')->nullable();
            $table->date('date_fin')->nullable();
            $table->string('statut', 20)->default('proposee');
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['etudiant_id', 'statut']);
        });

        Schema::create('parcours_reorientations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etudiant_id')->constrained('etudiants')->cascadeOnDelete();
            $table->foreignId('ancien_programme_id')->nullable()->constrained('programmes')->nullOnDelete();
            $table->foreignId('ancien_niveau_id')->nullable()->constrained('niveaux')->nullOnDelete();
            $table->string('ancien_libelle', 200)->nullable();
            $table->foreignId('nouveau_programme_id')->constrained('programmes')->cascadeOnDelete();
            $table->foreignId('nouveau_niveau_id')->nullable()->constrained('niveaux')->nullOnDelete();
            $table->string('nouveau_libelle', 200)->nullable();
            $table->foreignId('annee_universitaire_id')->constrained('annees_universitaires')->cascadeOnDelete();
            $table->string('decision', 20)->nullable();
            $table->text('justification')->nullable();
            $table->string('statut', 20)->default('proposee');
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['etudiant_id', 'statut']);
        });

        Schema::create('parcours_passerelles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etudiant_id')->constrained('etudiants')->cascadeOnDelete();
            $table->foreignId('programme_origine_id')->constrained('programmes')->cascadeOnDelete();
            $table->foreignId('niveau_origine_id')->nullable()->constrained('niveaux')->nullOnDelete();
            $table->foreignId('programme_cible_id')->constrained('programmes')->cascadeOnDelete();
            $table->foreignId('niveau_cible_id')->nullable()->constrained('niveaux')->nullOnDelete();
            $table->unsignedInteger('credits_obtenus')->default(0);
            $table->decimal('moyenne', 5, 2)->nullable();
            $table->json('conditions')->nullable();
            $table->string('decision_admission', 20)->nullable();
            $table->text('justification')->nullable();
            $table->string('statut', 20)->default('proposee');
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['etudiant_id', 'statut']);
        });

        Schema::create('parcours_alertes_risque', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etudiant_id')->constrained('etudiants')->cascadeOnDelete();
            $table->foreignId('inscription_id')->nullable()->constrained('inscriptions_academiques')->nullOnDelete();
            $table->string('niveau', 20)->default('faible');
            $table->unsignedInteger('score')->default(0);
            $table->json('motifs');
            $table->timestamp('calcule_le')->useCurrent();
            $table->foreignId('vu_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('vu_le')->nullable();
            $table->string('statut', 20)->default('nouvelle');
            $table->timestamps();
            $table->index(['etudiant_id', 'niveau']);
            $table->index(['statut', 'niveau']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcours_alertes_risque');
        Schema::dropIfExists('parcours_passerelles');
        Schema::dropIfExists('parcours_reorientations');
        Schema::dropIfExists('parcours_dispenses');
        Schema::dropIfExists('parcours_equivalences');
        Schema::dropIfExists('parcours_simulations');
        Schema::dropIfExists('parcours_plans');
        Schema::dropIfExists('parcours_regles');
    }
};
