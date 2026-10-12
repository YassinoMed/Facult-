<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Correctif d'intégrité pour les tables de présences enseignants (§4/§8) :
 *
 * 1. Les clés étrangères `teacher_presences.schedule_slot_id` et
 *    `teacher_id` (ainsi que `teacher_pointages.teacher_id`) ont été créées
 *    en CASCADE sur la base de développement AVANT la correction de la
 *    migration d'origine : la suppression d'un créneau ou d'un enseignant
 *    y détruirait l'historique des présences déjà validées. Cette migration
 *    les remplace par RESTRICT (la migration d'origine est corrigée pour
 *    les nouvelles installations — pattern documenté du projet).
 *
 * 2. Enregistre la tolérance administrative de retard dans le registre
 *    central des paramètres (jamais de constante en dur comme seule source).
 *
 * Driver-safe : ALTER FOREIGN n'existe pas en SQLite — les bases de tests
 * (fraîches) reçoivent déjà les bonnes contraintes via la migration d'origine
 * corrigée ; cette migration ne fait rien sur SQLite (documenté).
 */
return new class extends Migration
{
    private const PARAMETRES = [
        [
            'cle' => 'presences.tolerance_retard_minutes', 'valeur' => '15', 'type' => 'entier',
            'categorie' => 'presences',
            'description' => 'Tolérance de retard (minutes) au-delà de laquelle un pointage d’arrivée devient un retard potentiel à vérifier (rapprochement enseignants/emploi du temps).',
        ],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::table('teacher_presences', function (Blueprint $table) {
                $table->dropForeign(['schedule_slot_id']);
                $table->dropForeign(['teacher_id']);
            });

            Schema::table('teacher_presences', function (Blueprint $table) {
                $table->foreign('schedule_slot_id')->references('id')->on('schedule_slots')->restrictOnDelete();
                $table->foreign('teacher_id')->references('id')->on('teachers')->restrictOnDelete();
            });

            Schema::table('teacher_pointages', function (Blueprint $table) {
                $table->dropForeign(['teacher_id']);
            });

            Schema::table('teacher_pointages', function (Blueprint $table) {
                $table->foreign('teacher_id')->references('id')->on('teachers')->restrictOnDelete();
            });
        }

        foreach (self::PARAMETRES as $parametre) {
            DB::table('parametres')->updateOrInsert(
                ['cle' => $parametre['cle']],
                $parametre + ['created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::table('teacher_presences', function (Blueprint $table) {
                $table->dropForeign(['schedule_slot_id']);
                $table->dropForeign(['teacher_id']);
            });

            Schema::table('teacher_presences', function (Blueprint $table) {
                $table->foreign('schedule_slot_id')->references('id')->on('schedule_slots')->cascadeOnDelete();
                $table->foreign('teacher_id')->references('id')->on('teachers')->cascadeOnDelete();
            });

            Schema::table('teacher_pointages', function (Blueprint $table) {
                $table->dropForeign(['teacher_id']);
            });

            Schema::table('teacher_pointages', function (Blueprint $table) {
                $table->foreign('teacher_id')->references('id')->on('teachers')->cascadeOnDelete();
            });
        }

        DB::table('parametres')
            ->whereIn('cle', array_column(self::PARAMETRES, 'cle'))
            ->delete();
    }
};
