<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 17 — Portail alumni.
 *
 * Quatre tables ADDITIVES et RÉVERSIBLES. Aucune colonne n'est ajoutée aux
 * tables centrales et aucun calcul de diplomation n'est introduit :
 * `diplomes` est un registre officiel alimenté UNIQUEMENT par import
 * (CSV/API). L'absence de source officielle reste une absence, jamais une
 * déduction depuis les notes ou les crédits.
 *
 * Minimisation :
 * - `diplomes` ne duplique ni nom, ni prénom, ni CIN : l'identité reste
 *   dans `etudiants`, reliée par `etudiant_id`.
 * - `profils_alumni` ne contient que des coordonnées volontairement
 *   communiquées (consentement opt-in explicite), jamais de dossier
 *   académique (notes, crédits, décisions).
 * - `consentements_alumni` est un journal APPEND-ONLY : aucune mise à jour,
 *   aucune suppression (garanti aussi au niveau du modèle).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diplomes', function (Blueprint $table) {
            $table->id();
            // Numéro officiel : unique, jamais généré par la plateforme.
            $table->string('numero_diplome', 60)->unique();
            // L'identité reste dans `etudiants` : aucune duplication de PII.
            $table->foreignId('etudiant_id')->constrained('etudiants')->restrictOnDelete();
            $table->foreignId('faculte_id')->constrained('facultes')->restrictOnDelete();
            $table->foreignId('programme_id')->nullable()->constrained('programmes')->restrictOnDelete();
            $table->foreignId('type_diplome_id')->nullable()->constrained('type_diplomes')->restrictOnDelete();
            $table->date('date_delivrance');
            $table->string('mention', 40)->nullable();
            $table->string('source', 20)->default('csv'); // csv | api
            $table->string('reference_import', 40)->nullable();
            $table->timestamp('importe_le');
            $table->timestamps();

            $table->index(['faculte_id', 'date_delivrance']);
            $table->index(['programme_id', 'date_delivrance']);
            $table->index(['etudiant_id', 'date_delivrance']);
        });

        Schema::create('imports_diplomes', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('fichier_nom', 180);
            // Empreinte du contenu : un rejeu du même fichier ne crée
            // jamais un second registre.
            $table->string('fichier_sha256', 64)->unique();
            $table->string('source', 20)->default('csv'); // csv | api
            $table->string('statut', 20)->default('termine'); // termine | partiel | rejete
            $table->unsignedInteger('lignes_total')->default(0);
            $table->unsignedInteger('lignes_importees')->default(0);
            $table->unsignedInteger('lignes_rejetees')->default(0);
            // Rapport SANS PII : numéro de ligne + code de motif uniquement.
            $table->json('rapport')->nullable();
            $table->foreignId('importe_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['statut', 'created_at']);
        });

        Schema::create('profils_alumni', function (Blueprint $table) {
            $table->id();
            // Un seul profil annuaire par personne.
            $table->foreignId('etudiant_id')->unique()->constrained('etudiants')->restrictOnDelete();
            // Diplôme qui a servi de support au lien de consentement.
            $table->foreignId('diplome_id')->nullable()->constrained('diplomes')->nullOnDelete();
            $table->foreignId('faculte_id')->constrained('facultes')->restrictOnDelete();
            $table->foreignId('programme_id')->nullable()->constrained('programmes')->restrictOnDelete();

            $table->string('nom_affichage', 150);
            // Coordonnées PRIVÉES : affichées uniquement selon `visibilite`
            // et jamais à un employeur (aucune route employeur n'existe).
            $table->string('email_contact', 190)->nullable();
            $table->string('telephone_contact', 30)->nullable();
            $table->string('poste_actuel', 150)->nullable();
            $table->string('employeur', 150)->nullable();
            $table->string('secteur', 80)->nullable();
            $table->string('ville', 100)->nullable();
            $table->string('pays', 80)->nullable();
            $table->string('linkedin', 255)->nullable();

            // agregats | annuaire | annuaire_contact
            $table->string('visibilite', 30)->default('agregats');
            $table->timestamp('consentement_le')->nullable();
            $table->string('consentement_version', 20)->nullable();
            $table->timestamp('retire_le')->nullable();
            $table->timestamps();

            $table->index(['faculte_id', 'retire_le']);
            $table->index(['visibilite', 'retire_le']);
        });

        Schema::create('consentements_alumni', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profil_alumni_id')->constrained('profils_alumni')->restrictOnDelete();
            $table->string('action', 20); // opt_in | opt_out
            $table->string('version_texte', 20);
            $table->string('canal', 20)->default('lien_signe'); // lien_signe | admin
            $table->string('cle_idempotence', 64)->unique();
            $table->foreignId('diplome_id')->nullable()->constrained('diplomes')->nullOnDelete();
            $table->timestamp('survenu_le');
            $table->timestamps();

            $table->index(['profil_alumni_id', 'survenu_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consentements_alumni');
        Schema::dropIfExists('profils_alumni');
        Schema::dropIfExists('imports_diplomes');
        Schema::dropIfExists('diplomes');
    }
};
