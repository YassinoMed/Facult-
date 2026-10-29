<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Simulateur temporel (ST-03) — tables 100 % ADDITIVES et réversibles :
 * - scenarios_temporels : définitions VERSIONNÉES (critères, contraintes,
 *   objectifs DECLARATIFS JSON) — jamais de règle inventée par le système ;
 * - executions_temporelles : exécutions TRAÇABLES et IDEMPOTENTES
 *   (UNIQUE scenario+hash_entrees+graine) — hash_resultat figé ;
 * - snapshots_executions : états agrégés par pas — JAMAIS d'écriture
 *   métier ni de copie de données personnelles (compteurs uniquement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenarios_temporels', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('libelle', 200);
            $table->string('domaine', 40)->default('effectifs_cohortes');
            $table->foreignId('faculte_id')->nullable()->constrained('facultes')->restrictOnDelete();
            $table->json('criteres'); // delta_base, bornes, horizon, taux SAISIS
            $table->json('contraintes'); // descripteurs déclaratifs
            $table->json('objectifs'); // descripteurs déclaratifs + poids SAISIS
            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('executions_temporelles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scenario_temporel_id')->constrained('scenarios_temporels')->restrictOnDelete();
            $table->string('statut', 20)->default('terminee'); // terminee | echouee
            $table->string('hash_entrees', 64);
            $table->string('hash_resultat', 64)->nullable();
            $table->string('graine', 64);
            $table->string('version_moteur', 10);
            $table->unsignedSmallInteger('nb_variantes')->default(1);
            $table->unsignedSmallInteger('horizon_annees')->default(1);
            $table->json('resultat')->nullable(); // classement + explications FIGÉS
            $table->unsignedInteger('duree_ms')->nullable();
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Nom EXPLICITE court : l'auto-généré (70 car.) dépasse la limite
            // MySQL de 64 identifiants (erreur 1059 — SQLite ne l'impose pas).
            $table->unique(['scenario_temporel_id', 'hash_entrees', 'graine'], 'uq_exec_tempo_idempotence');
        });

        Schema::create('snapshots_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_temporelle_id')->constrained('executions_temporelles')->cascadeOnDelete();
            $table->unsignedSmallInteger('pas');
            $table->string('temps_simule', 10); // libellé d'année simulée
            $table->json('etat'); // agrégats par cohorte — aucun champ nominatif
            $table->timestamps();

            $table->index(['execution_temporelle_id', 'pas']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snapshots_executions');
        Schema::dropIfExists('executions_temporelles');
        Schema::dropIfExists('scenarios_temporels');
    }
};
