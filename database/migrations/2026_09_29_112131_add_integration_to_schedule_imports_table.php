<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module Schedule (§17) : origine de l'import (web/api) pour l'audit.
 * Additif, valeur par défaut web.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_imports', function (Blueprint $table) {
            $table->string('integration', 10)->default('web')->after('format');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_imports', function (Blueprint $table) {
            $table->dropColumn('integration');
        });
    }
};
