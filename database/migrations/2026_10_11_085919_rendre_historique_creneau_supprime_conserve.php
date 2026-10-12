<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LOOP SCHEDULE-03 — Conservation de l'audit après suppression d'un
 * créneau : `schedule_changes.schedule_slot_id` devient nullable avec
 * `nullOnDelete` (au lieu du cascade qui effaçait l'historique, y
 * compris la ligne 'deleted', en même temps que le créneau).
 * Additive et réversible : le down supprime les lignes orphelines
 * (slot NULL, créées par cette fonctionnalité) puis restaure le cascade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_changes', function (Blueprint $table) {
            $table->dropForeign(['schedule_slot_id']);
        });

        Schema::table('schedule_changes', function (Blueprint $table) {
            $table->unsignedBigInteger('schedule_slot_id')->nullable()->change();
            $table->foreign('schedule_slot_id')->references('id')->on('schedule_slots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('schedule_changes')->whereNull('schedule_slot_id')->delete();

        Schema::table('schedule_changes', function (Blueprint $table) {
            $table->dropForeign(['schedule_slot_id']);
        });

        Schema::table('schedule_changes', function (Blueprint $table) {
            $table->unsignedBigInteger('schedule_slot_id')->nullable(false)->change();
            $table->foreign('schedule_slot_id')->references('id')->on('schedule_slots')->cascadeOnDelete();
        });
    }
};
