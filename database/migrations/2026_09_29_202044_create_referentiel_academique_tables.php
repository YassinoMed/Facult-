<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel académique LMD (§2-20) : hiérarchie
 * faculté → département/domaine → mention → spécialité → programme
 * → niveau → semestre → UE → ECUE (matieres), + maquettes versionnées.
 *
 * Intégrité : FK restrict (jamais de suppression en cascade d'une
 * donnée référentielle utilisée), unicité des codes dans leur contexte,
 * valeurs positives (crédits, coefficients, volumes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('type_diplomes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('nom', 100);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::create('departements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculte_id')->constrained('facultes')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('nom', 150);
            $table->text('description')->nullable();
            $table->string('responsable', 150)->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->unique(['faculte_id', 'code']);
            $table->index('faculte_id');
        });

        Schema::create('domaines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculte_id')->constrained('facultes')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('sigle', 20)->nullable();
            $table->string('nom', 150);
            $table->text('description')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->unique(['faculte_id', 'code']);
        });

        Schema::create('mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domaine_id')->constrained('domaines')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('sigle', 20)->nullable();
            $table->string('nom', 150);
            $table->text('description')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->unique(['domaine_id', 'code']);
        });

        Schema::create('specialites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mention_id')->constrained('mentions')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('nom', 150);
            $table->text('description')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->unique(['mention_id', 'code']);
        });

        Schema::create('programmes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculte_id')->constrained('facultes')->restrictOnDelete();
            $table->foreignId('departement_id')->nullable()->constrained('departements')->restrictOnDelete();
            $table->foreignId('specialite_id')->nullable()->constrained('specialites')->restrictOnDelete();
            $table->foreignId('type_diplome_id')->nullable()->constrained('type_diplomes')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('nom', 200);
            $table->string('intitule_officiel', 255)->nullable();
            $table->string('sigle', 20)->nullable();
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('duree_annees')->default(3);
            $table->unsignedTinyInteger('semestres_nombre')->default(6);
            $table->unsignedSmallInteger('credits_requis')->default(180);
            $table->string('responsable', 150)->nullable();
            $table->enum('mode', ['initiale', 'continue', 'alternance'])->default('initiale');
            $table->date('date_debut')->nullable();
            $table->date('date_fin')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->unique(['faculte_id', 'code']);
            $table->index(['faculte_id', 'actif']);
        });

        Schema::create('niveaux', function (Blueprint $table) {
            $table->id();
            $table->foreignId('programme_id')->constrained('programmes')->restrictOnDelete();
            $table->string('code', 10);
            $table->string('nom', 100);
            $table->unsignedTinyInteger('ordre')->default(1);
            $table->unsignedTinyInteger('semestres_nombre')->default(2);
            $table->unsignedSmallInteger('credits_attendus')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->unique(['programme_id', 'code']);
        });

        Schema::create('semestres_lmd', function (Blueprint $table) {
            $table->id();
            $table->foreignId('niveau_id')->constrained('niveaux')->restrictOnDelete();
            $table->string('code', 10);
            $table->string('nom', 100);
            $table->unsignedTinyInteger('numero');
            $table->unsignedTinyInteger('ordre')->default(1);
            $table->unsignedSmallInteger('credits_attendus')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->unique(['niveau_id', 'code']);
        });

        Schema::create('unites_enseignement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('semestre_id')->constrained('semestres_lmd')->restrictOnDelete();
            $table->foreignId('programme_id')->constrained('programmes')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('sigle', 20)->nullable();
            $table->string('nom', 200);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('credits');
            $table->decimal('coefficient', 4, 2)->default(1);
            $table->enum('type', ['fondamentale', 'transversale', 'optionnelle', 'professionnelle'])->default('fondamentale');
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->unique(['semestre_id', 'code']);
            $table->index('programme_id');
        });

        Schema::create('maquettes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('programme_id')->constrained('programmes')->restrictOnDelete();
            $table->foreignId('annee_universitaire_id')->constrained('annees_universitaires')->restrictOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->enum('statut', ['draft', 'review', 'approved', 'active', 'archived'])->default('draft');
            $table->date('date_effet')->nullable();
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('date_validation')->nullable();
            $table->foreignId('version_precedente_id')->nullable()->constrained('maquettes')->nullOnDelete();
            $table->text('commentaire')->nullable();
            $table->timestamps();
            $table->unique(['programme_id', 'annee_universitaire_id', 'version']);
            $table->index(['programme_id', 'statut']);
        });

        Schema::create('maquette_ue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maquette_id')->constrained('maquettes')->cascadeOnDelete();
            $table->foreignId('ue_id')->constrained('unites_enseignement')->restrictOnDelete();
            $table->foreignId('semestre_id')->constrained('semestres_lmd')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['maquette_id', 'ue_id']);
        });

        Schema::create('ecue_prerequis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matiere_id')->constrained('matieres')->cascadeOnDelete();
            $table->foreignId('prerequis_id')->constrained('matieres')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['matiere_id', 'prerequis_id']);
        });

        // §3.1 : enrichissement additif des établissements (nullable).
        Schema::table('facultes', function (Blueprint $table) {
            $table->string('sigle', 20)->nullable()->after('code');
            $table->string('adresse', 255)->nullable()->after('description');
            $table->string('telephone', 30)->nullable()->after('adresse');
            $table->string('email', 150)->nullable()->after('telephone');
            $table->string('site_web', 255)->nullable()->after('email');
        });

        // §14 : Matiere = ECUE (ue, volumes, évaluation) — additif.
        Schema::table('matieres', function (Blueprint $table) {
            $table->foreignId('ue_id')->nullable()->after('semestre')
                ->constrained('unites_enseignement')->restrictOnDelete();
            $table->text('description')->nullable()->after('ue_id');
            $table->unsignedSmallInteger('volume_cm')->default(0)->after('description');
            $table->unsignedSmallInteger('volume_td')->default(0)->after('volume_cm');
            $table->unsignedSmallInteger('volume_tp')->default(0)->after('volume_td');
            $table->string('type_evaluation', 30)->nullable()->after('volume_tp');
            $table->boolean('actif')->default(true)->after('type_evaluation');
        });
    }

    public function down(): void
    {
        Schema::table('matieres', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ue_id');
            $table->dropColumn(['description', 'volume_cm', 'volume_td', 'volume_tp', 'type_evaluation', 'actif']);
        });
        Schema::table('facultes', function (Blueprint $table) {
            $table->dropColumn(['sigle', 'adresse', 'telephone', 'email', 'site_web']);
        });
        Schema::dropIfExists('ecue_prerequis');
        Schema::dropIfExists('maquette_ue');
        Schema::dropIfExists('maquettes');
        Schema::dropIfExists('unites_enseignement');
        Schema::dropIfExists('semestres_lmd');
        Schema::dropIfExists('niveaux');
        Schema::dropIfExists('programmes');
        Schema::dropIfExists('specialites');
        Schema::dropIfExists('mentions');
        Schema::dropIfExists('domaines');
        Schema::dropIfExists('departements');
        Schema::dropIfExists('type_diplomes');
    }
};
