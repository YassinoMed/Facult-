<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inscriptions_academiques', function (Blueprint $table) {
            // Phase 3 §40 : dashboard/recherche/API filtrent programme,
            // statut, type — index légers, additifs, réversibles.
            $table->index('programme_id', 'idx_inscriptions_programme');
            $table->index('statut', 'idx_inscriptions_statut');
            $table->index('type', 'idx_inscriptions_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inscriptions_academiques', function (Blueprint $table) {
            $table->dropIndex('idx_inscriptions_programme');
            $table->dropIndex('idx_inscriptions_statut');
            $table->dropIndex('idx_inscriptions_type');
        });
    }
};
