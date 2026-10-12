<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Évaluation académique (§26-35).
 *
 * Tables dédiées préfixées `evaluation_` — aucune colonne en table
 * centrale. La note officielle reste `notes` ; `evaluation_notes`
 * est la couche de saisie par épreuve, synchronisée vers `notes`
 * à la validation (pas de deuxième système de notes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_regles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('annee_universitaire_id')->constrained('annees_universitaires')->restrictOnDelete();
            $table->foreignId('programme_id')->nullable()->constrained('programmes')->restrictOnDelete();
            $table->decimal('seuil_validation', 4, 2)->default(10);
            $table->decimal('seuil_rattrapage', 4, 2)->default(8);
            $table->decimal('note_eliminatoire', 4, 2)->nullable();
            $table->boolean('compensation_ecue')->default(true);
            $table->boolean('compensation_ue')->default(true);
            $table->boolean('compensation_semestre')->default(true);
            $table->string('arrondi', 10)->default('2dec');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->unique(['annee_universitaire_id', 'programme_id', 'version'], 'eval_regles_annee_prog_version_uq');
            $table->index(['annee_universitaire_id', 'actif']);
        });

        Schema::create('evaluation_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('annee_universitaire_id')->constrained('annees_universitaires')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('nom', 150);
            $table->string('type', 20);
            $table->date('date_debut');
            $table->date('date_fin');
            $table->string('statut', 20)->default('draft');
            $table->text('description')->nullable();
            $table->json('regles')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verrouillee_at')->nullable();
            $table->timestamps();
            $table->unique(['annee_universitaire_id', 'code']);
            $table->index(['annee_universitaire_id', 'statut']);
        });

        Schema::create('evaluation_examens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('evaluation_sessions')->cascadeOnDelete();
            $table->foreignId('annee_universitaire_id')->constrained('annees_universitaires')->restrictOnDelete();
            $table->foreignId('semestre_lmd_id')->nullable()->constrained('semestres_lmd')->restrictOnDelete();
            $table->foreignId('programme_id')->nullable()->constrained('programmes')->restrictOnDelete();
            $table->foreignId('niveau_id')->nullable()->constrained('niveaux')->restrictOnDelete();
            $table->foreignId('groupe_id')->nullable()->constrained('groups')->restrictOnDelete();
            $table->string('libelle', 200);
            $table->string('type', 30)->default('examen');
            $table->string('statut', 20)->default('draft');
            $table->text('description')->nullable();
            $table->text('observations')->nullable();
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['session_id', 'statut']);
            $table->index(['annee_universitaire_id', 'programme_id']);
        });

        Schema::create('evaluation_epreuves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examen_id')->constrained('evaluation_examens')->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('evaluation_sessions')->restrictOnDelete();
            $table->foreignId('annee_universitaire_id')->constrained('annees_universitaires')->restrictOnDelete();
            $table->foreignId('matiere_id')->constrained('matieres')->restrictOnDelete();
            $table->foreignId('ue_id')->nullable()->constrained('unites_enseignement')->restrictOnDelete();
            $table->foreignId('groupe_id')->nullable()->constrained('groups')->restrictOnDelete();
            $table->foreignId('sous_groupe_id')->nullable()->constrained('sous_groupes')->restrictOnDelete();
            $table->foreignId('salle_id')->nullable()->constrained('rooms')->restrictOnDelete();
            $table->date('date');
            $table->time('heure_debut');
            $table->time('heure_fin');
            $table->unsignedSmallInteger('duree_minutes')->nullable();
            $table->string('type_evaluation', 30)->default('examen');
            $table->decimal('coefficient', 4, 2)->default(1);
            $table->decimal('bareme', 5, 2)->default(20);
            $table->decimal('note_max', 5, 2)->default(20);
            $table->unsignedInteger('capacite_necessaire')->default(0);
            $table->string('statut', 20)->default('draft');
            $table->timestamps();
            $table->index(['session_id', 'date']);
            $table->index(['salle_id', 'date']);
            $table->index(['groupe_id', 'date']);
            $table->index(['matiere_id', 'session_id']);
        });

        Schema::create('evaluation_surveillants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('epreuve_id')->constrained('evaluation_epreuves')->cascadeOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('teachers')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role', 20)->default('surveillant');
            $table->foreignId('salle_id')->nullable()->constrained('rooms')->restrictOnDelete();
            $table->string('statut', 20)->default('assigne');
            $table->string('presence', 20)->nullable();
            $table->text('observations')->nullable();
            $table->timestamps();
            $table->index(['epreuve_id', 'statut']);
        });

        Schema::create('evaluation_candidats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('epreuve_id')->constrained('evaluation_epreuves')->cascadeOnDelete();
            $table->foreignId('etudiant_id')->constrained('etudiants')->cascadeOnDelete();
            $table->foreignId('inscription_pedagogique_id')->nullable()->constrained('inscriptions_pedagogiques')->restrictOnDelete();
            $table->string('statut', 25)->default('present');
            $table->text('justification')->nullable();
            $table->foreignId('decide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['epreuve_id', 'etudiant_id']);
            $table->index(['epreuve_id', 'statut']);
        });

        Schema::create('evaluation_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('epreuve_id')->constrained('evaluation_epreuves')->cascadeOnDelete();
            $table->foreignId('etudiant_id')->constrained('etudiants')->cascadeOnDelete();
            $table->foreignId('candidat_id')->nullable()->constrained('evaluation_candidats')->nullOnDelete();
            $table->foreignId('note_id')->nullable()->constrained('notes')->nullOnDelete();
            $table->decimal('valeur', 5, 2)->nullable();
            $table->decimal('note_max', 5, 2)->default(20);
            $table->decimal('coefficient', 4, 2)->default(1);
            $table->string('statut', 20)->default('draft');
            $table->text('commentaire')->nullable();
            $table->foreignId('saisi_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('valide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verrouillee_at')->nullable();
            $table->timestamps();
            $table->unique(['epreuve_id', 'etudiant_id']);
            $table->index(['epreuve_id', 'statut']);
        });

        Schema::create('evaluation_notes_historique', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_note_id')->constrained('evaluation_notes')->cascadeOnDelete();
            $table->decimal('ancienne_valeur', 5, 2)->nullable();
            $table->decimal('nouvelle_valeur', 5, 2)->nullable();
            $table->string('motif', 500);
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['evaluation_note_id', 'created_at']);
        });

        Schema::create('evaluation_deliberations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('annee_universitaire_id')->constrained('annees_universitaires')->restrictOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('evaluation_sessions')->restrictOnDelete();
            $table->foreignId('semestre_lmd_id')->nullable()->constrained('semestres_lmd')->restrictOnDelete();
            $table->foreignId('programme_id')->nullable()->constrained('programmes')->restrictOnDelete();
            $table->foreignId('niveau_id')->nullable()->constrained('niveaux')->restrictOnDelete();
            $table->foreignId('groupe_id')->nullable()->constrained('groups')->restrictOnDelete();
            $table->string('statut', 20)->default('draft');
            $table->json('regles_appliquees')->nullable();
            $table->json('anomalies')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('verrouillee_at')->nullable();
            $table->timestamps();
            $table->index(['annee_universitaire_id', 'statut']);
        });

        Schema::create('evaluation_jury_membres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deliberation_id')->constrained('evaluation_deliberations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('teachers')->restrictOnDelete();
            $table->string('fonction', 30)->default('membre');
            $table->boolean('present')->default(false);
            $table->timestamp('signature_at')->nullable();
            $table->timestamps();
            $table->index(['deliberation_id', 'fonction']);
        });

        Schema::create('evaluation_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deliberation_id')->constrained('evaluation_deliberations')->cascadeOnDelete();
            $table->foreignId('etudiant_id')->constrained('etudiants')->cascadeOnDelete();
            $table->foreignId('inscription_academique_id')->nullable()->constrained('inscriptions_academiques')->restrictOnDelete();
            $table->decimal('moyenne', 5, 2)->nullable();
            $table->unsignedSmallInteger('credits_obtenus')->default(0);
            $table->unsignedSmallInteger('credits_total')->default(0);
            $table->unsignedSmallInteger('ues_validees')->default(0);
            $table->unsignedSmallInteger('ues_total')->default(0);
            $table->string('decision', 30)->default('a_examiner');
            $table->text('commentaire')->nullable();
            $table->boolean('publie')->default(false);
            $table->timestamps();
            $table->unique(['deliberation_id', 'etudiant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_decisions');
        Schema::dropIfExists('evaluation_jury_membres');
        Schema::dropIfExists('evaluation_deliberations');
        Schema::dropIfExists('evaluation_notes_historique');
        Schema::dropIfExists('evaluation_notes');
        Schema::dropIfExists('evaluation_candidats');
        Schema::dropIfExists('evaluation_surveillants');
        Schema::dropIfExists('evaluation_epreuves');
        Schema::dropIfExists('evaluation_examens');
        Schema::dropIfExists('evaluation_sessions');
        Schema::dropIfExists('evaluation_regles');
    }
};
