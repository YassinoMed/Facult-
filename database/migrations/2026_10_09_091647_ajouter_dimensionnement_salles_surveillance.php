<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dimensionnement des surveillants selon les salles et leur capacité.
 *
 * - `surveillance_constraint_configs.min_par_salle` : minimum général par
 *   salle (défaut 1, héritage si la salle ne définit rien).
 * - `rooms.min_surveillants` / `rooms.ratio_surveillants` : exceptions
 *   spécifiques à une salle (NULL = héritage des valeurs générales).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surveillance_constraint_configs', function (Blueprint $table) {
            $table->unsignedTinyInteger('min_par_salle')->default(1)->after('min_surveillants');
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->unsignedTinyInteger('min_surveillants')->nullable()->after('capacite');
            $table->unsignedInteger('ratio_surveillants')->nullable()->after('min_surveillants');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['ratio_surveillants', 'min_surveillants']);
        });

        Schema::table('surveillance_constraint_configs', function (Blueprint $table) {
            $table->dropColumn('min_par_salle');
        });
    }
};
