<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOOP GLOBAL EXAMENS — GOUVERNANCE, PRÉSENCE ET CONTINUITÉ (28/10/2026)
 *
 * Migration STRICTEMENT ADDITIVE. Aucune colonne existante n'est modifiée
 * ni supprimée (sauf les colonnes ajoutées par cette même migration dans
 * son `down()`), aucune donnée n'est réécrite, aucun backfill détruisant.
 *
 * 1. `evaluation_groupes_virtuels` + membres : regroupement d'examen
 *    calculé (plusieurs groupes sources, même module), sans jamais
 *    toucher aux inscriptions ni aux groupes académiques d'origine.
 * 2. `evaluation_planning_validations` : circuit de certification puis
 *    double approbation par personnes DISTINCTES avant publication,
 *    avec rejet motivé, escalade et gel institutionnel.
 * 3. `evaluation_regularisations_presence` + colonnes de présence
 *    provisoire sur `evaluation_candidats` : l'absence n'est jamais
 *    convertie automatiquement en absence injustifiée.
 * 4. `evaluation_delegations_urgence` : suppléance bornée, révocable,
 *    sans auto-validation, tracée.
 * 5. Colonnes de qualification des incidents (gravité, responsable,
 *    délai cible, preuve, validation).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── LOT B — Groupes virtuels d'examen ──────────────────────────
        Schema::create('evaluation_groupes_virtuels', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('examen_id')->constrained('evaluation_examens')->cascadeOnDelete();
            $table->foreignId('epreuve_id')->nullable()->constrained('evaluation_epreuves')->nullOnDelete();
            $table->foreignId('matiere_id')->nullable()->constrained('matieres')->nullOnDelete();
            $table->foreignId('programme_id')->nullable()->constrained('programmes')->nullOnDelete();
            $table->foreignId('niveau_id')->nullable()->constrained('niveaux')->nullOnDelete();
            $table->string('filiere', 150)->nullable();
            $table->string('libelle', 150);
            // Empreinte déterministe (examen + module + tuple académique +
            // sources triées) : deux propositions identiques sont
            // reconnaissables sans ambiguïté.
            $table->string('cle', 64);
            // propose | remplace | annule
            $table->string('statut', 20)->default('propose');
            // Sources retenues (identifiants de `groups`) : table
            // d'audit lisible, en plus de la table de membres.
            $table->json('sources');
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('remplace_par_id')->nullable()
                ->constrained('evaluation_groupes_virtuels')->nullOnDelete();
            $table->timestamps();

            $table->index(['examen_id', 'matiere_id', 'statut'], 'idx_gv_examen_matiere_statut');
            $table->index(['examen_id', 'cle'], 'idx_gv_examen_cle');
        });

        Schema::create('evaluation_groupe_virtuel_membres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('groupe_virtuel_id')
                ->constrained('evaluation_groupes_virtuels')->cascadeOnDelete();
            $table->foreignId('etudiant_id')->constrained('etudiants')->cascadeOnDelete();
            $table->foreignId('groupe_source_id')->nullable()->constrained('groups')->nullOnDelete();
            // Noms de contraintes explicites : les noms auto-générés
            // dépassent la limite MySQL de 64 caractères.
            $table->foreignId('inscription_academique_id')->nullable();
            $table->foreign('inscription_academique_id', 'fk_gv_membre_inscr_acad')
                ->references('id')->on('inscriptions_academiques')->nullOnDelete();
            $table->foreignId('inscription_pedagogique_id')->nullable();
            $table->foreign('inscription_pedagogique_id', 'fk_gv_membre_inscr_peda')
                ->references('id')->on('inscriptions_pedagogiques')->nullOnDelete();
            // eligible | non_eligible | anomalie — décision explicable,
            // jamais une simple présence dans un groupe.
            $table->string('decision', 20);
            $table->string('regle', 60)->nullable();
            $table->string('motif', 500)->nullable();
            $table->timestamps();

            $table->unique(['groupe_virtuel_id', 'etudiant_id'], 'uniq_gv_membre_etudiant');
            $table->index(['groupe_virtuel_id', 'decision'], 'idx_gv_membre_decision');
        });

        // ── LOT D — Certification, double approbation, publication ─────
        Schema::create('evaluation_planning_validations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('examen_id')->constrained('evaluation_examens')->cascadeOnDelete();
            $table->unsignedInteger('version');
            // soumis | certifie | approuve | rejete | escalade | publie
            $table->string('statut', 30)->default('soumis');
            $table->string('empreinte_planning', 64)->nullable();

            $table->foreignId('prepare_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('soumis_at')->nullable();

            $table->foreignId('certifie_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('certifie_at')->nullable();

            $table->foreignId('approbateur_1')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approuve_1_at')->nullable();
            $table->foreignId('approbateur_2')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approuve_2_at')->nullable();

            $table->foreignId('rejete_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejete_at')->nullable();
            $table->text('motif_rejet')->nullable();

            $table->foreignId('escalade_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('escalade_at')->nullable();
            $table->text('motif_escalade')->nullable();

            $table->foreignId('publie_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('publie_at')->nullable();

            // Exception de gel : motif obligatoire + auteur habilité.
            $table->boolean('exceptionnel')->default(false);
            $table->text('motif_exceptionnel')->nullable();

            $table->timestamps();

            $table->unique(['examen_id', 'version'], 'uniq_validation_examen_version');
            $table->index(['examen_id', 'statut'], 'idx_validation_examen_statut');
        });

        // ── LOT E — Présence provisoire, retard horodaté, régularisation ─
        Schema::table('evaluation_candidats', function (Blueprint $table) {
            $table->time('heure_arrivee')->nullable();
            // Une absence constatée reste provisoire tant qu'une décision
            // habilitée ne l'a pas tranchée (§2.9).
            $table->boolean('presence_provisoire')->default(false);
        });

        Schema::create('evaluation_regularisations_presence', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('candidat_id')->constrained('evaluation_candidats')->cascadeOnDelete();
            // en_attente | approuvee | rejetee
            $table->string('statut', 20)->default('en_attente');
            $table->string('statut_initial', 25);
            $table->string('statut_final', 25)->nullable();
            $table->string('motif', 500);
            // Référence de preuve (chemin privé ou identifiant de pièce) :
            // jamais le contenu de la pièce dans la table.
            $table->string('preuve_reference', 500)->nullable();
            $table->foreignId('demande_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('demande_at');
            $table->foreignId('decide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decide_at')->nullable();
            $table->text('motif_decision')->nullable();
            $table->timestamps();

            $table->index(['candidat_id', 'statut'], 'idx_regul_candidat_statut');
        });

        // ── LOT F — Suppléance d'urgence bornée ────────────────────────
        Schema::create('evaluation_delegations_urgence', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('delegant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('delegue_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('faculte_id')->nullable()->constrained('facultes')->nullOnDelete();
            $table->foreignId('session_id')->nullable()
                ->constrained('evaluation_sessions')->nullOnDelete();
            // Liste fermée des actions d'urgence autorisées : tout le reste
            // est refusé côté serveur (jamais une simple permission RBAC).
            $table->json('actions');
            $table->timestamp('debut_at');
            $table->timestamp('fin_at');
            $table->string('motif', 500);
            // active | revolue | expiree
            $table->string('statut', 20)->default('active');
            $table->timestamp('revoquee_at')->nullable();
            $table->foreignId('revoquee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['delegue_id', 'fin_at'], 'idx_delegation_delegue_fin');
        });

        Schema::table('evaluation_incidents', function (Blueprint $table) {
            // faible | normale | haute | critique
            $table->string('gravite', 20)->default('normale');
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('delai_cible_at')->nullable();
            $table->timestamp('resolu_at')->nullable();
            $table->text('actions_prises')->nullable();
            $table->json('preuves')->nullable();
            $table->foreignId('valide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('valide_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_incidents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsable_id');
            $table->dropConstrainedForeignId('valide_par');
            $table->dropColumn([
                'gravite', 'delai_cible_at', 'resolu_at', 'actions_prises', 'preuves', 'valide_at',
            ]);
        });

        Schema::table('evaluation_candidats', function (Blueprint $table) {
            $table->dropColumn(['heure_arrivee', 'presence_provisoire']);
        });

        Schema::dropIfExists('evaluation_delegations_urgence');
        Schema::dropIfExists('evaluation_regularisations_presence');
        Schema::dropIfExists('evaluation_planning_validations');
        Schema::dropIfExists('evaluation_groupe_virtuel_membres');
        Schema::dropIfExists('evaluation_groupes_virtuels');
    }
};
