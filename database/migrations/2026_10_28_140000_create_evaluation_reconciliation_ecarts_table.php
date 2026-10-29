<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §2.15 — dossier d'écart de réconciliation tri-sources.
 *
 * Chaque divergence papier/numérique est PERSISTÉE avec l'instantané des
 * trois sources. Le dossier suit ensuite un circuit à quatre yeux :
 *   ouvert → instruit (vérificateur) → validé (personne distincte)
 *
 * L'instantané et l'empreinte de source rendent la décision vérifiable :
 * si la situation a changé depuis la détection, l'instruction est refusée
 * et une nouvelle analyse doit être lancée. Aucun écart n'est supprimé :
 * la validation est un état final, pas un remplacement silencieux.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_reconciliation_ecarts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('epreuve_id')
                ->constrained('evaluation_epreuves')
                ->cascadeOnDelete();
            $table->foreignId('candidat_id')
                ->constrained('evaluation_candidats')
                ->cascadeOnDelete();

            // ouvert | instruit | valide
            $table->string('statut', 20)->default('ouvert');

            // Instantané des sources au moment de la détection : le dossier
            // reste lisible même si la base évolue ensuite.
            $table->string('statut_papier', 30);
            $table->string('statut_numerique', 30);
            $table->timestamp('journal_horodate')->nullable();
            $table->text('nature_divergence');
            $table->string('empreinte_source', 64);

            $table->foreignId('detecte_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('detecte_at');

            $table->foreignId('instructeur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('instruit_at')->nullable();
            $table->string('statut_retenu', 30)->nullable();
            $table->text('motif_instruction')->nullable();
            $table->string('preuve_reference', 500)->nullable();

            $table->foreignId('valideur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('valide_at')->nullable();
            $table->text('commentaire_validation')->nullable();

            $table->timestamps();

            // Rejouer la même analyse ne crée pas un doublon : l'empreinte
            // couvre l'épreuve, le candidat et la divergence constatée.
            $table->unique(
                ['epreuve_id', 'candidat_id', 'empreinte_source'],
                'uniq_ecart_epreuve_candidat_empreinte'
            );
            $table->index(['epreuve_id', 'statut'], 'idx_ecart_epreuve_statut');
            $table->index(['candidat_id', 'statut'], 'idx_ecart_candidat_statut');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_reconciliation_ecarts');
    }
};
