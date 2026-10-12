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
        // Phase 9 §19/§48 : les pièces remplacées restent historisées
        // (conservation institutionnelle), la plus récente fait foi.
        Schema::table('request_attachments', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('size');
            $table->index(['online_request_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('request_attachments', function (Blueprint $table) {
            $table->dropIndex(['online_request_id', 'version']);
            $table->dropColumn('version');
        });
    }
};
