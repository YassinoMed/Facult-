<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aligne le vocabulaire de gravité des incidents sur le domaine métier
 * (`mineure | majeure | critique`) et normalise les valeurs héritées de
 * la première version du schéma (`faible | normale | haute`).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('evaluation_incidents')->where('gravite', 'faible')->update(['gravite' => 'mineure']);
        DB::table('evaluation_incidents')->where('gravite', 'normale')->update(['gravite' => 'mineure']);
        DB::table('evaluation_incidents')->where('gravite', 'haute')->update(['gravite' => 'majeure']);

        Schema::table('evaluation_incidents', function (Blueprint $table) {
            $table->string('gravite', 20)->default('mineure')->change();
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_incidents', function (Blueprint $table) {
            $table->string('gravite', 20)->default('normale')->change();
        });
    }
};
