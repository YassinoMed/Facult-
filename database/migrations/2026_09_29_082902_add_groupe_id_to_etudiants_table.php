<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module Schedule : rattachement étudiant → groupe (additif, nullable).
 * Seule modification d'une table du cœur, sans toucher aux données.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etudiants', function (Blueprint $table) {
            $table->foreignId('groupe_id')->nullable()->after('faculte_id')
                ->constrained('groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('etudiants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('groupe_id');
        });
    }
};
