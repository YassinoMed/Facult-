<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indicateur de paiement externe, porté par l'inscription annuelle.
 *
 * Le paiement est effectué sur un site externe : l'application ne conserve
 * qu'un simple oui/non. Aucune facture ni transaction locale n'est créée.
 * Migration strictement additive, sans suppression de données.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inscriptions_academiques', function (Blueprint $table) {
            $table->boolean('paye')->default(false)->after('regime');
        });
    }

    public function down(): void
    {
        Schema::table('inscriptions_academiques', function (Blueprint $table) {
            $table->dropColumn('paye');
        });
    }
};
