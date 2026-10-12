<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * LOT 2 §11 : une seule année active — garde DB en plus de la
     * garde applicative. `active_unique` ne vaut 'Y' que pour l'année
     * active (NULL sinon : les NULL multiples sont autorisés par
     * l'index unique, sur MySQL comme SQLite).
     */
    public function up(): void
    {
        Schema::table('annees_universitaires', function (Blueprint $table) {
            $table->string('active_unique', 1)->nullable()->after('active');
            $table->unique('active_unique', 'annees_universitaires_active_unique');
        });

        // Assainissement déterministe : si plusieurs années actives
        // (possible avant cette garde), seule la plus récente reste
        // active, les autres sont désactivées (jamais supprimées).
        $actives = DB::table('annees_universitaires')
            ->where('active', true)->orderByDesc('id')->pluck('id');

        if ($actives->count() > 1) {
            DB::table('annees_universitaires')
                ->whereIn('id', $actives->slice(1)->all())
                ->update(['active' => false]);
        }

        DB::table('annees_universitaires')
            ->where('active', true)
            ->update(['active_unique' => 'Y']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('annees_universitaires', function (Blueprint $table) {
            $table->dropUnique('annees_universitaires_active_unique');
            $table->dropColumn('active_unique');
        });
    }
};
