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
        // Phase 14 §14.6 : une correction de note post-préparation
        // invalide les résultats dépendants (re-préparation exigée).
        Schema::table('evaluation_deliberations', function (Blueprint $table) {
            $table->timestamp('resultats_invalides_le')->nullable()->after('verrouillee_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('evaluation_deliberations', function (Blueprint $table) {
            $table->dropColumn('resultats_invalides_le');
        });
    }
};
