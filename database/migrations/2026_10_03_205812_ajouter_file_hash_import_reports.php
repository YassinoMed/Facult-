<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotence fichier : empreinte SHA-256 du fichier source sur chaque
 * rapport d'import (détection IMPORT_ALREADY_PROCESSED).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_reports', function (Blueprint $table) {
            $table->char('file_hash', 64)->nullable()->after('filename');
            $table->index('file_hash');
        });
    }

    public function down(): void
    {
        Schema::table('import_reports', function (Blueprint $table) {
            $table->dropIndex(['file_hash']);
            $table->dropColumn('file_hash');
        });
    }
};
