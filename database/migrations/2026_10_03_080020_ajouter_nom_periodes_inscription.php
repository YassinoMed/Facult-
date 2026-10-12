<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOOP périodes §1/§10 : libellé administratif (ex. « Inscriptions
 * principales ») — affichage et audit uniquement, jamais une règle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registration_periods', function (Blueprint $table) {
            $table->string('nom', 150)->nullable()->after('type_periode');
        });
    }

    public function down(): void
    {
        Schema::table('registration_periods', function (Blueprint $table) {
            $table->dropColumn('nom');
        });
    }
};
