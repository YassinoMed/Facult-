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
        // Phase 14 §14.5 : traçabilité des exécutions de calcul
        // (simulation rejouable + officiel audité). Les résultats
        // restent dans evaluation_decisions ; ici seul le run.
        Schema::create('calculation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deliberation_id')->constrained('evaluation_deliberations')->cascadeOnDelete();
            $table->enum('mode', ['simulation', 'officiel'])->default('simulation');
            $table->json('regles');
            $table->char('hash_entrees', 64);
            $table->json('resume');
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['deliberation_id', 'mode']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calculation_runs');
    }
};
