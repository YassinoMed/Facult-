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
        Schema::table('evaluation_sessions', function (Blueprint $table) {
            // Phase 5 §43 : rattrapage rattaché à sa session principale
            // (même année). Nullable : principales et exceptionnelles
            // n'ont pas d'origine. Restrict : jamais de suppression en
            // cascade silencieuse.
            $table->foreignId('session_origine_id')->nullable()
                ->after('annee_universitaire_id')
                ->constrained('evaluation_sessions')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('evaluation_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('session_origine_id');
        });
    }
};
