<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module Schedule (§5) : période de validité du semestre
 * (nullable : les plannings existants restent valides).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->date('starts_at')->nullable()->after('nom');
            $table->date('ends_at')->nullable()->after('starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropColumn(['starts_at', 'ends_at']);
        });
    }
};
