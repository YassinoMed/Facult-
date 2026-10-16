<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module universitaire de campagnes d'envoi de courriels (LOOP-MAIL-CAMPAIGNS-01) :
     * campagnes de masse, groupes enregistrés dynamiques/fixes, destinataires par lot,
     * traçabilité et déduplication stricte par condensat d'adresse.
     */
    public function up(): void
    {
        Schema::create('groupes_destinataires', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 120);
            $table->string('description', 255)->nullable();
            $table->string('type', 30); // dynamique_etudiants, dynamique_enseignants, liste_fixe
            $table->string('audience', 20)->default('etudiants'); // etudiants, enseignants, mixte
            $table->json('criteres')->nullable();
            $table->json('membres_fixes')->nullable();
            $table->boolean('est_archive')->default(false);
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'est_archive']);
            $table->index('est_archive');
        });

        Schema::create('campagnes_courriel', function (Blueprint $table) {
            $table->id();
            $table->string('titre', 150);
            $table->string('sujet', 255);
            $table->string('type_ciblage', 30); // individuel, liste_fixe, dynamique_etudiants, dynamique_enseignants, groupe_enregistre
            $table->foreignId('modele_id')->nullable()->constrained('modeles_courriel')->nullOnDelete();
            $table->unsignedInteger('modele_version')->nullable();
            $table->text('contenu_texte')->nullable();
            $table->longText('contenu_html')->nullable();
            $table->json('filtres')->nullable();
            $table->foreignId('groupe_enregistre_id')->nullable()->constrained('groupes_destinataires')->nullOnDelete();
            $table->string('statut', 30)->default('brouillon'); // brouillon, planifiee, en_attente, en_cours, terminee, terminee_avec_erreurs, annulee, echouee
            $table->string('cle_idempotence', 64)->unique();
            $table->timestamp('planifiee_a')->nullable();
            $table->timestamp('commencee_a')->nullable();
            $table->timestamp('terminee_a')->nullable();
            $table->unsignedInteger('total_destinataires')->default(0);
            $table->unsignedInteger('total_envoyes')->default(0);
            $table->unsignedInteger('total_echecs')->default(0);
            $table->unsignedInteger('total_ignores')->default(0);
            $table->unsignedSmallInteger('taille_lot')->default(50);
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['statut', 'planifiee_a']);
            $table->index(['statut', 'created_at']);
        });

        Schema::create('campagne_courriel_destinataires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campagne_id')->constrained('campagnes_courriel')->cascadeOnDelete();
            $table->string('type_destinataire', 20)->default('etudiant'); // etudiant, enseignant, externe
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('nom_complet', 150)->nullable();
            $table->string('email', 254);
            $table->char('email_hash', 64);
            $table->string('statut', 20)->default('en_attente'); // en_attente, en_cours, envoye, echec, annule, ignore
            $table->unsignedSmallInteger('tentatives')->default(0);
            $table->string('derniere_erreur', 255)->nullable();
            $table->foreignId('courriel_id')->nullable()->constrained('courriels')->nullOnDelete();
            $table->string('correlation_id', 64)->nullable();
            $table->timestamp('envoye_a')->nullable();
            $table->json('donnees')->nullable();
            $table->timestamps();

            $table->unique(['campagne_id', 'email_hash'], 'uniq_campagne_destinataire_hash');
            $table->index(['campagne_id', 'statut']);
            $table->index('email_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campagne_courriel_destinataires');
        Schema::dropIfExists('campagnes_courriel');
        Schema::dropIfExists('groupes_destinataires');
    }
};
