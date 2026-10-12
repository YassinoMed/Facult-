<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajout des seuils et minima configurables pour les salles de grande capacité.
 *
 * Permet à l'administration de définir au niveau général/faculté/session :
 * - `seuil_grande_salle` : seuil d'effectif ou de capacité à partir duquel une
 *   salle est qualifiée de « grande salle » (ex. 100 places ou candidats) ;
 * - `min_surveillants_grande_salle` : plancher minimal de surveillants obligatoire
 *   pour les grandes salles (ex. 3 ou 4), sans écraser une règle explicite de salle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surveillance_constraint_configs', function (Blueprint $table) {
            $table->unsignedSmallInteger('seuil_grande_salle')->nullable()->after('ratio_etudiants');
            $table->unsignedTinyInteger('min_surveillants_grande_salle')->nullable()->after('seuil_grande_salle');
        });
    }

    public function down(): void
    {
        Schema::table('surveillance_constraint_configs', function (Blueprint $table) {
            $table->dropColumn(['min_surveillants_grande_salle', 'seuil_grande_salle']);
        });
    }
};
