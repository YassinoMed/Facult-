<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('evaluation_types_evaluation', function (Blueprint $table) {
            // Phase 5 §26 : référentiel configurable des types
            // d'évaluation (jamais de liste en dur côté épreuves).
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('libelle', 100);
            $table->decimal('coefficient_defaut', 5, 2)->default(1);
            $table->unsignedSmallInteger('duree_defaut_minutes')->default(120);
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        // Graine alignée sur Examen::TYPES (génération auto des
        // épreuves + imports historiques utilisent ces codes).
        $types = [
            ['examen', 'Examen final', 1, 120],
            ['controle', 'Contrôle continu', 1, 60],
            ['partiel', 'Partiel', 1, 90],
            ['devoir', 'Devoir surveillé', 1, 60],
            ['oral', 'Oral', 1, 20],
            ['projet', 'Projet', 1, 0],
            ['soutenance', 'Soutenance', 1, 30],
            ['rattrapage', 'Rattrapage', 1, 120],
        ];

        foreach ($types as [$code, $libelle, $coefficient, $duree]) {
            DB::table('evaluation_types_evaluation')->updateOrInsert(
                ['code' => $code],
                ['libelle' => $libelle, 'coefficient_defaut' => $coefficient,
                    'duree_defaut_minutes' => $duree, 'actif' => true,
                    'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evaluation_types_evaluation');
    }
};
