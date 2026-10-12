<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — Population universitaire (§4-28) : tables nouvelles +
 * extensions additives (nullable) des tables existantes. Aucune
 * colonne existante modifiée ou supprimée (zéro régression).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grades_enseignants', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('nom', 100);
            $table->unsignedTinyInteger('ordre')->default(0);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::create('fonctions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('nom', 100);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        // Personnel administratif = profil d'un compte User (§14, §43 :
        // le dossier reste indépendant de l'authentification).
        Schema::create('personnels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('matricule', 30)->unique();
            $table->foreignId('fonction_id')->nullable()->constrained('fonctions')->restrictOnDelete();
            $table->foreignId('departement_id')->nullable()->constrained('departements')->restrictOnDelete();
            $table->string('bureau', 50)->nullable();
            $table->date('date_entree')->nullable();
            $table->enum('statut', ['actif', 'inactif', 'suspendu'])->default('actif');
            $table->timestamps();
        });

        // Affectations historisées étudiant → groupe (§17-18).
        Schema::create('affectations_groupe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etudiant_id')->constrained('etudiants')->cascadeOnDelete();
            $table->foreignId('groupe_id')->constrained('groups')->restrictOnDelete();
            $table->foreignId('annee_universitaire_id')->constrained('annees_universitaires')->restrictOnDelete();
            $table->date('date_debut');
            $table->date('date_fin')->nullable();
            $table->enum('statut', ['active', 'cloturee'])->default('active');
            $table->string('motif', 255)->nullable();
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['etudiant_id', 'statut']);
            $table->index(['groupe_id', 'statut']);
        });

        // Historique des situations (jamais écrasé, §10, §46).
        Schema::create('situations_etudiant', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etudiant_id')->constrained('etudiants')->cascadeOnDelete();
            $table->enum('type', ['statut', 'groupe', 'programme', 'suspension', 'transfert', 'reinscription', 'note']);
            $table->string('ancienne_valeur', 255)->nullable();
            $table->string('nouvelle_valeur', 255)->nullable();
            $table->string('motif', 255)->nullable();
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('date_effet');
            $table->date('date_fin')->nullable();
            $table->timestamps();
            $table->index(['etudiant_id', 'type']);
        });

        // Inscriptions pédagogiques adossées à la maquette active (§23-24).
        Schema::create('inscriptions_pedagogiques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inscription_id')->constrained('inscriptions_academiques')->cascadeOnDelete();
            $table->foreignId('semestre_id')->constrained('semestres_lmd')->restrictOnDelete();
            $table->foreignId('ue_id')->constrained('unites_enseignement')->restrictOnDelete();
            $table->foreignId('matiere_id')->constrained('matieres')->restrictOnDelete();
            $table->enum('statut', ['inscrit', 'valide', 'ajourne'])->default('inscrit');
            $table->timestamps();
            $table->unique(['inscription_id', 'matiere_id'], 'uniq_pedago_inscription_matiere');
        });

        // Extensions additives — enseignants (§11).
        Schema::table('teachers', function (Blueprint $table) {
            $table->string('matricule', 30)->nullable()->unique()->after('email');
            $table->foreignId('grade_id')->nullable()->after('matricule')
                ->constrained('grades_enseignants')->restrictOnDelete();
            $table->foreignId('departement_id')->nullable()->after('grade_id')
                ->constrained('departements')->restrictOnDelete();
            $table->foreignId('specialite_id')->nullable()->after('departement_id')
                ->constrained('specialites')->restrictOnDelete();
            $table->string('bureau', 50)->nullable()->after('specialite_id');
            $table->date('date_embauche')->nullable()->after('bureau');
            $table->string('statut', 20)->default('actif')->after('date_embauche');
        });

        // Extensions additives — groupes (§15-16).
        Schema::table('groups', function (Blueprint $table) {
            $table->foreignId('programme_id')->nullable()->after('faculte_id')
                ->constrained('programmes')->restrictOnDelete();
            $table->foreignId('niveau_id')->nullable()->after('programme_id')
                ->constrained('niveaux')->restrictOnDelete();
            $table->foreignId('annee_universitaire_id')->nullable()->after('niveau_id')
                ->constrained('annees_universitaires')->restrictOnDelete();
            $table->unsignedSmallInteger('capacite')->nullable()->after('filiere');
        });

        // Extensions additives — étudiants (§4, rattachement LMD).
        Schema::table('etudiants', function (Blueprint $table) {
            $table->foreignId('programme_id')->nullable()->after('groupe_id')
                ->constrained('programmes')->restrictOnDelete();
            $table->foreignId('niveau_id')->nullable()->after('programme_id')
                ->constrained('niveaux')->restrictOnDelete();
        });

        // Extensions additives — inscriptions (§19-22).
        Schema::table('inscriptions_academiques', function (Blueprint $table) {
            $table->foreignId('programme_id')->nullable()->after('etudiant_id')
                ->constrained('programmes')->restrictOnDelete();
            $table->foreignId('niveau_id')->nullable()->after('programme_id')
                ->constrained('niveaux')->restrictOnDelete();
            $table->enum('type', ['premiere', 'reinscription', 'transfert', 'mutation', 'reprise', 'equivalence', 'autre'])
                ->default('premiere')->after('statut');
            $table->enum('regime', ['initiale', 'continue', 'alternance'])
                ->default('initiale')->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('inscriptions_academiques', function (Blueprint $table) {
            $table->dropConstrainedForeignId('programme_id');
            $table->dropConstrainedForeignId('niveau_id');
            $table->dropColumn(['type', 'regime']);
        });
        Schema::table('etudiants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('programme_id');
            $table->dropConstrainedForeignId('niveau_id');
        });
        Schema::table('groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('programme_id');
            $table->dropConstrainedForeignId('niveau_id');
            $table->dropConstrainedForeignId('annee_universitaire_id');
            $table->dropColumn('capacite');
        });
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('grade_id');
            $table->dropConstrainedForeignId('departement_id');
            $table->dropConstrainedForeignId('specialite_id');
            $table->dropColumn(['matricule', 'bureau', 'date_embauche', 'statut']);
        });
        Schema::dropIfExists('inscriptions_pedagogiques');
        Schema::dropIfExists('situations_etudiant');
        Schema::dropIfExists('affectations_groupe');
        Schema::dropIfExists('personnels');
        Schema::dropIfExists('fonctions');
        Schema::dropIfExists('grades_enseignants');
    }
};
