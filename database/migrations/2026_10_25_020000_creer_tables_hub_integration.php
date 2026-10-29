<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 18 — Hub d'intégration : extension additive du socle existant
 * (WebhookVerifier, IntegrationLog immuable, pipeline DataExchange).
 *
 * Tables ADDITIVES et RÉVERSIBLES du module uniquement :
 * - `contrats_connecteur` : contrats VERSIONNÉS par connecteur (schema SAISI,
 *   jamais inventé) avec séparation d'ENVIRONNEMENT (production|sandbox) ;
 * - `audits_idempotence` : verdict d'idempotence SAISI connecteur par
 *   connecteur avec preuve datée (jamais de conformité globale affirmée) ;
 * - `rejeux_integration` : rejeu BORNÉ (plafond applicatif) et AUTORISÉ-
 *   AUDITÉ d'un échange en échec ; `integration_logs` reste INSERT-ONLY
 *   (aucune modification de l'existant — règle du cahier).
 *
 * Aucune colonne ajoutée aux tables existantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contrats_connecteur', function (Blueprint $table) {
            $table->id();
            $table->string('connecteur', 100); // clé du DefinitionRegistry (ex: 'maquettes')
            $table->string('version', 20); // version du contrat (saisie, jamais auto)
            $table->json('schema')->nullable(); // structure attendue, SAISIE par l'administration
            $table->string('statut', 20)->default('brouillon'); // brouillon | actif | deprecie
            $table->string('environnement', 20)->default('production'); // production | sandbox
            $table->text('changelog')->nullable();
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('active_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('active_le')->nullable();
            $table->timestamps();

            $table->unique(['connecteur', 'version']);
            $table->index(['connecteur', 'statut']);
        });

        Schema::create('audits_idempotence', function (Blueprint $table) {
            $table->id();
            $table->string('connecteur', 100);
            $table->string('verdict', 20); // verifie | doute | absent (SAISI, jamais calculé)
            $table->text('preuve'); // preuve datée : test exécuté, référence, commentaire
            $table->foreignId('audite_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('audite_le')->nullable();
            $table->timestamps();

            $table->index('connecteur');
        });

        Schema::create('rejeux_integration', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_log_id')->constrained('integration_logs')->restrictOnDelete();
            $table->unsignedSmallInteger('tentative_numero'); // 1..PLAFOND_REJEUX
            $table->text('motif'); // autorisation motivée
            $table->foreignId('autorise_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('autorise_le')->nullable();
            $table->string('statut', 20)->default('autorise'); // autorise | execute
            $table->timestamps();

            $table->unique(['integration_log_id', 'tentative_numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rejeux_integration');
        Schema::dropIfExists('audits_idempotence');
        Schema::dropIfExists('contrats_connecteur');
    }
};
