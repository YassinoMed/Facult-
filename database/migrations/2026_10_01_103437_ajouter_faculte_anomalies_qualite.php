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
        // Phase 10 §10.10 : périmètre faculté persisté au balayage
        // (NULL = détection globale, visible tous périmètres).
        Schema::table('anomalies_qualite', function (Blueprint $table) {
            $table->foreignId('faculte_id')->nullable()->after('id')
                ->constrained('facultes')->nullOnDelete();
            $table->index(['faculte_id', 'statut']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('anomalies_qualite', function (Blueprint $table) {
            $table->dropIndex(['faculte_id', 'statut']);
            $table->dropConstrainedForeignId('faculte_id');
        });
    }
};
