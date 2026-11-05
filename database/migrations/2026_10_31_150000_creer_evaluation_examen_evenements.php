<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOOP EX-LIFECYCLE — historisation append-only des transitions d'examen.
 *
 * Écart démontré (audit §10) : seules les transitions de gouvernance
 * étaient persistées (evaluation_planning_evenements) ; les transitions
 * simples (draft→planned→…→archived) ne laissaient qu'une trace fichier.
 *
 * Table additive : aucune colonne existante modifiée, aucune table
 * touchée, `down()` réversible (drop de la seule table créée).
 * Convention identique à `evaluation_planning_evenements` : pas
 * d'updated_at, avant/après JSON, acteur nullOnDelete.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('evaluation_examen_evenements')) {
            return;
        }

        Schema::create('evaluation_examen_evenements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examen_id')->constrained('evaluation_examens')->cascadeOnDelete();
            // transition_effectuee | transition_refusee | creation
            $table->string('action', 30);
            $table->foreignId('acteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('statut_avant', 20);
            $table->string('statut_apres', 20);
            $table->text('motif')->nullable();
            $table->json('metadonnees')->nullable();
            // Append-only : pas d'updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['examen_id', 'created_at'], 'idx_examen_evenement_examen_date');
            $table->index(['action', 'created_at'], 'idx_examen_evenement_action_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_examen_evenements');
    }
};
