<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 (§5) : CIN nullable — étudiants étrangers ou cas sans pièce
 * nationale. Unicité conservée (MySQL/SQLite : NULL multiples autorisés).
 * Parcours public garde CIN obligatoire (processus national) ; back-office
 * et import acceptent NULL (doublons alors détectés par email).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etudiants', function (Blueprint $table) {
            $table->string('cin', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('etudiants', function (Blueprint $table) {
            $table->string('cin', 30)->nullable(false)->change();
        });
    }
};
