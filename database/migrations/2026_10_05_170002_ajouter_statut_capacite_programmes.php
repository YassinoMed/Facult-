<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * LOT 2 §13 : statut workflow + capacité indicative du programme.
     * `actif` est conservé et synchronisé (vrai ssi statut = 'actif')
     * pour ne casser aucun lecteur existant.
     */
    public function up(): void
    {
        Schema::table('programmes', function (Blueprint $table) {
            $table->string('statut', 20)->default('actif')->after('actif');
            $table->unsignedSmallInteger('capacite')->nullable()->after('statut');
        });

        DB::table('programmes')->where('actif', true)->update(['statut' => 'actif']);
        DB::table('programmes')->where('actif', false)->update(['statut' => 'inactif']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('programmes', function (Blueprint $table) {
            $table->dropColumn(['statut', 'capacite']);
        });
    }
};
