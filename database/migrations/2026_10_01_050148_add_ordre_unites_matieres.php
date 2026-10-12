<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 (§18-19) : ordre d'affichage UE/ECUE (drag-drop).
 * Additif nullable-safe : défaut 0 = ordre alphabétique conservé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unites_enseignement', function (Blueprint $table) {
            $table->unsignedSmallInteger('ordre')->default(0)->after('coefficient');
            $table->index(['semestre_id', 'ordre'], 'idx_ue_semestre_ordre');
        });

        Schema::table('matieres', function (Blueprint $table) {
            $table->unsignedSmallInteger('ordre')->default(0)->after('coefficient');
            $table->index(['ue_id', 'ordre'], 'idx_matieres_ue_ordre');
        });
    }

    public function down(): void
    {
        Schema::table('matieres', function (Blueprint $table) {
            $table->dropIndex('idx_matieres_ue_ordre');
            $table->dropColumn('ordre');
        });

        Schema::table('unites_enseignement', function (Blueprint $table) {
            $table->dropIndex('idx_ue_semestre_ordre');
            $table->dropColumn('ordre');
        });
    }
};
