<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 26 — Localisation des salles : campus, bâtiment, étage,
 * rattachement faculté (nullable : parc mutualisé possible).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('campus', 100)->nullable()->after('nom');
            $table->string('batiment', 100)->nullable()->after('campus');
            $table->string('etage', 20)->nullable()->after('batiment');
            $table->foreignId('faculte_id')->nullable()->after('etage')->constrained('facultes')->restrictOnDelete();
            $table->index(['faculte_id', 'campus']);
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropIndex(['faculte_id', 'campus']);
            $table->dropConstrainedForeignId('faculte_id');
            $table->dropColumn(['campus', 'batiment', 'etage']);
        });
    }
};
