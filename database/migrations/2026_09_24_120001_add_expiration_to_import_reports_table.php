<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §80-82 : statut « expired » + durée de conservation configurable
     * des fichiers d'export (données personnelles → purge automatique).
     */
    public function up(): void
    {
        Schema::table('import_reports', function (Blueprint $table) {
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'cancelled', 'expired'])
                ->default('pending')
                ->change();
            $table->timestamp('expires_at')->nullable()->after('duration_ms');
        });

        Schema::table('import_reports', function (Blueprint $table) {
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('import_reports', function (Blueprint $table) {
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'cancelled'])
                ->default('pending')
                ->change();
            $table->dropIndex(['expires_at']);
            $table->dropColumn('expires_at');
        });
    }
};
