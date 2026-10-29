<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOOP GLOBAL EXAMENS — AUDIT DE GOUVERNANCE (28/10/2026)
 *
 * Migration STRICTEMENT ADDITIVE, complémentaire de
 * `2026_10_28_100000_loop_global_examens_gouvernance.php` (déjà figée,
 * jamais modifiée) :
 *
 * 1. `evaluation_planning_validations` : colonnes manquantes pour
 *    historiser la date de gel réellement appliquée et la résolution
 *    motivée d'une escalade (sans écraser le motif d'escalade initial).
 * 2. `evaluation_planning_evenements` : journal APPEND-ONLY de chaque
 *    transition (acteur, action, avant/après, empreinte, motif).
 *    Aucune transition n'est prouvée sans une ligne d'événement.
 *
 * Aucune donnée existante n'est lue, réécrite ni supprimée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_planning_validations', function (Blueprint $table) {
            $table->timestamp('date_gel_appliquee')->nullable()->after('empreinte_planning');
            $table->boolean('escalade_resolue')->default(false)->after('motif_escalade');
            $table->foreignId('escalade_resolue_par')->nullable()->after('escalade_resolue')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('escalade_resolue_at')->nullable()->after('escalade_resolue_par');
            $table->text('resolution_escalade')->nullable()->after('escalade_resolue_at');
        });

        Schema::create('evaluation_planning_evenements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planning_validation_id')
                ->constrained('evaluation_planning_validations')->cascadeOnDelete();
            $table->foreignId('examen_id')->constrained('evaluation_examens')->cascadeOnDelete();
            $table->unsignedInteger('version');
            // soumis | certifie | approuve_1 | approuve_2 | approuve | rejete
            // | escalade | escalade_resolue | publie | version_creee
            $table->string('action', 30);
            $table->foreignId('acteur_id')->nullable()->constrained('users')->nullOnDelete();
            // Rôle effectif au moment de l'action (préparateur, certificateur,
            // approbateur, autorité, publicateur) : le contrôle de séparation
            // se relit dans l'audit, jamais dans une simple session.
            $table->string('role_contexte', 30)->nullable();
            $table->text('motif')->nullable();
            $table->string('empreinte_planning', 64)->nullable();
            $table->json('avant')->nullable();
            $table->json('apres')->nullable();
            // Append-only : pas d'updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['planning_validation_id', 'created_at'], 'idx_evenement_validation_date');
            $table->index(['examen_id', 'version'], 'idx_evenement_examen_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_planning_evenements');

        Schema::table('evaluation_planning_validations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('escalade_resolue_par');
            $table->dropColumn([
                'date_gel_appliquee',
                'escalade_resolue',
                'escalade_resolue_at',
                'resolution_escalade',
            ]);
        });
    }
};
