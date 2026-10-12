<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 — Publication du planning : statut métier sur `schedules`
 * (table du module, aucune table centrale touchée).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->string('statut', 20)->default('brouillon')->after('actif');
            $table->timestamp('publie_a')->nullable()->after('statut');
            $table->index(['statut', 'groupe_id']);
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropIndex(['statut', 'groupe_id']);
            $table->dropColumn(['statut', 'publie_a']);
        });
    }
};
