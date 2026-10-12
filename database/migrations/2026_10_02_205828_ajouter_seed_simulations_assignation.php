<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reproductibilité contrôlée (§8) : seed stocké (même seed + mêmes
 * données = même répartition ; nouveau seed = nouvelle répartition)
 * + lignée des régénérations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignment_simulations', function (Blueprint $table) {
            $table->string('seed', 16)->nullable()->after('hash_entrees');
            $table->foreignId('simulation_origine_id')->nullable()->after('seed')
                ->constrained('assignment_simulations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assignment_simulations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('simulation_origine_id');
            $table->dropColumn('seed');
        });
    }
};
