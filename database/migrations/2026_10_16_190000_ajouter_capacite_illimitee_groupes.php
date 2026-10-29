<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data Mesh lot hardening : triptyque des capacités de groupe (Phase C).
 * Additif et réversible : nouvelle colonne nullable-booléenne, défaut false
 * (tout l'existant reste INCONNU, aucun comportement moteur modifié ici).
 * - DEFINIE : capacite renseignée (règle opposable existante).
 * - ILLIMITEE : capacite NULL + capacite_illimitee = true (décision métier explicite).
 * - INCONNUE : capacite NULL + flag false (à confirmer, signalée en qualité).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->boolean('capacite_illimitee')->default(false)->after('capacite');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn('capacite_illimitee');
        });
    }
};
