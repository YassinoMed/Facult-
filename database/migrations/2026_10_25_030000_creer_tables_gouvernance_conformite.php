<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 19 — Centre de gouvernance et conformité : extension additive.
 *
 * Tables ADDITIVES et RÉVERSIBLES du module uniquement :
 * - `journal_changements_permissions` : trace STRUCTURÉE des créations de
 *   comptes et des changements de rôle (Observer User — aucun contrôleur
 *   existant modifié) ;
 * - `politiques_retention` : rétention par journal, SAISIE par l'administration
 *   (durée en jours), APPROUVÉE avant application — le module ne SUPPRIME
 *   RIEN : il rapporte l'état de conformité (jamais d'immuabilité promise) ;
 * - `journal_acces_journaux` : accès aux VUES de gouvernance (qui a consulté
 *   quelle vue, quand) — proportionné : le FAIT de consulter, jamais le
 *   contenu lu (réserve éthique du cahier).
 *
 * Aucune colonne ajoutée aux tables existantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_changements_permissions', function (Blueprint $table) {
            $table->id();
            // nullOnDelete (pattern du dépôt) : un compte supprimé n'efface
            // JAMAIS la trace de gouvernance — les lignes restent, liées à NULL.
            $table->foreignId('utilisateur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ancien_role', 40)->nullable(); // null = création de compte
            $table->string('nouveau_role', 40);
            $table->foreignId('acteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 20)->default('http'); // http | interne
            $table->timestamps();

            $table->index(['utilisateur_id', 'created_at']);
        });

        Schema::create('politiques_retention', function (Blueprint $table) {
            $table->id();
            $table->string('source_journal', 50)->unique(); // journal réel uniquement
            $table->unsignedInteger('duree_jours'); // SAISIE, jamais inventée
            $table->string('statut', 20)->default('brouillon'); // brouillon | approuvee
            $table->text('motif')->nullable();
            $table->foreignId('approuve_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approuve_le')->nullable();
            $table->timestamps();
        });

        Schema::create('journal_acces_journaux', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilisateur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('vue', 100); // vue de gouvernance consultée
            $table->string('source_journal', 50)->nullable(); // filtre éventuel
            $table->timestamps();

            $table->index(['vue', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_acces_journaux');
        Schema::dropIfExists('politiques_retention');
        Schema::dropIfExists('journal_changements_permissions');
    }
};
