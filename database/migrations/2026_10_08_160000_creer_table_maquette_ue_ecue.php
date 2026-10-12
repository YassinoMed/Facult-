<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contexte d'un ECUE dans une maquette (LOOP référentiel §6B/§19 :
     * option B). Additive pure : aucune colonne existante touchée.
     * L'ECUE y porte ses valeurs ANNUELLES (coef/crédits/volumes) et les
     * règles Master (obligatoire/choix/conditions) au lieu de muter la
     * matière canonique (`matieres`, identité stable + historique).
     */
    public function up(): void
    {
        Schema::create('maquette_ue_ecue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maquette_ue_id')->constrained('maquette_ue')->cascadeOnDelete();
            $table->foreignId('matiere_id')->constrained('matieres')->restrictOnDelete();
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->decimal('coefficient', 4, 2)->default(1);
            $table->unsignedSmallInteger('credits')->default(0);
            $table->unsignedSmallInteger('volume_cm')->default(0);
            $table->unsignedSmallInteger('volume_td')->default(0);
            $table->unsignedSmallInteger('volume_tp')->default(0);
            $table->string('type_evaluation', 30)->nullable();
            $table->string('regime', 30)->nullable();
            $table->boolean('obligatoire')->default(true);
            $table->string('groupe_choix', 30)->nullable();
            $table->json('conditions')->nullable();
            $table->timestamps();
            $table->unique(['maquette_ue_id', 'matiere_id']);
            $table->index(['matiere_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maquette_ue_ecue');
    }
};
