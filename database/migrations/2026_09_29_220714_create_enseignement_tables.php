<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — Enseignement (§6-26) : types configurables, enseignements
 * (ECUE × type × année), affectations enseignants/groupes, sous-groupes,
 * seuils de service, composantes d'évaluation. ECUE = Matiere (AUCUN
 * doublon) ; slots de planning inchangés (Phase 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('types_enseignement', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('nom', 100);
            $table->text('description')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::create('enseignements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matiere_id')->constrained('matieres')->restrictOnDelete();
            $table->foreignId('type_id')->constrained('types_enseignement')->restrictOnDelete();
            $table->foreignId('annee_universitaire_id')->constrained('annees_universitaires')->restrictOnDelete();
            $table->foreignId('semestre_id')->nullable()->constrained('semestres_lmd')->restrictOnDelete();
            $table->foreignId('programme_id')->nullable()->constrained('programmes')->restrictOnDelete();
            $table->unsignedSmallInteger('volume_cm')->default(0);
            $table->unsignedSmallInteger('volume_td')->default(0);
            $table->unsignedSmallInteger('volume_tp')->default(0);
            $table->unsignedSmallInteger('volume_projet')->default(0);
            $table->unsignedSmallInteger('volume_autre')->default(0);
            $table->enum('statut', ['draft', 'planned', 'validated', 'active', 'completed', 'cancelled', 'archived'])->default('draft');
            $table->text('description')->nullable();
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['matiere_id', 'type_id', 'annee_universitaire_id'], 'uniq_ens_matiere_type_annee');
            $table->index(['programme_id', 'statut']);
        });

        Schema::create('affectations_enseignement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enseignement_id')->constrained('enseignements')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('teachers')->restrictOnDelete();
            $table->enum('role', ['responsable', 'principal', 'enseignant', 'intervenant', 'vacataire'])->default('enseignant');
            $table->decimal('volume_heures', 6, 1)->default(0);
            $table->date('date_debut')->nullable();
            $table->date('date_fin')->nullable();
            $table->enum('statut', ['active', 'cloturee'])->default('active');
            $table->string('motif', 255)->nullable();
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['enseignement_id', 'teacher_id', 'role'], 'uniq_aff_ens_role');
            $table->index(['teacher_id', 'statut']);
        });

        Schema::create('sous_groupes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('groupe_id')->constrained('groups')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('nom', 100);
            $table->enum('type', ['td', 'tp', 'autre'])->default('td');
            $table->unsignedSmallInteger('capacite')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->unique(['groupe_id', 'code']);
        });

        Schema::create('enseignement_groupe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enseignement_id')->constrained('enseignements')->cascadeOnDelete();
            $table->foreignId('groupe_id')->constrained('groups')->restrictOnDelete();
            $table->foreignId('sous_groupe_id')->nullable()->constrained('sous_groupes')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['enseignement_id', 'groupe_id', 'sous_groupe_id'], 'uniq_ens_groupe');
        });

        // Seuils de service configurables par grade/année (§20).
        Schema::create('seuils_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_id')->nullable()->constrained('grades_enseignants')->restrictOnDelete();
            $table->foreignId('faculte_id')->nullable()->constrained('facultes')->restrictOnDelete();
            $table->foreignId('annee_universitaire_id')->nullable()->constrained('annees_universitaires')->restrictOnDelete();
            $table->decimal('service_min', 6, 1)->default(0);
            $table->decimal('service_cible', 6, 1)->default(0);
            $table->decimal('service_max', 6, 1)->default(0);
            $table->timestamps();
        });

        // Composantes d'évaluation d'une ECUE (§24) : somme contrôlée = 100.
        Schema::create('composantes_evaluation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matiere_id')->constrained('matieres')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('libelle', 100);
            $table->decimal('pourcentage', 5, 2);
            $table->timestamps();
            $table->unique(['matiere_id', 'code']);
        });

        // Responsable pédagogique de l'ECUE (§17).
        Schema::table('matieres', function (Blueprint $table) {
            $table->foreignId('responsable_teacher_id')->nullable()->after('ue_id')
                ->constrained('teachers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('matieres', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsable_teacher_id');
        });
        Schema::dropIfExists('composantes_evaluation');
        Schema::dropIfExists('seuils_service');
        Schema::dropIfExists('enseignement_groupe');
        Schema::dropIfExists('sous_groupes');
        Schema::dropIfExists('affectations_enseignement');
        Schema::dropIfExists('enseignements');
        Schema::dropIfExists('types_enseignement');
    }
};
