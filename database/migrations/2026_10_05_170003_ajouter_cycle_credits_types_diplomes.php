<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * LOT 2 §14 : cycle LMD + crédits/durée de référence du type de
     * diplôme (valeurs par défaut indicatives — chaque programme
     * garde ses propres crédits requis).
     */
    public function up(): void
    {
        Schema::table('type_diplomes', function (Blueprint $table) {
            $table->string('cycle', 30)->nullable()->after('nom');
            $table->unsignedSmallInteger('credits_requis')->nullable()->after('cycle');
            $table->unsignedSmallInteger('duree_annees')->nullable()->after('credits_requis');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('type_diplomes', function (Blueprint $table) {
            $table->dropColumn(['cycle', 'credits_requis', 'duree_annees']);
        });
    }
};
