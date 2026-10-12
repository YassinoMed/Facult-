<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_compostages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('epreuve_id')->constrained('evaluation_epreuves')->cascadeOnDelete();
            $table->foreignId('etudiant_id')->constrained('etudiants')->cascadeOnDelete();
            // Colonnes miroirs pour compatibilité d'interrogation (§4)
            $table->unsignedBigInteger('evaluation_id')->nullable();
            $table->unsignedBigInteger('student_id')->nullable();
            $table->string('numero', 32);
            $table->timestamp('verrouille_at')->nullable();
            $table->timestamps();

            // Unicité stricte par épreuve/évaluation : un étudiant = un seul compostage par épreuve
            $table->unique(['epreuve_id', 'etudiant_id'], 'eval_comp_epreuve_etudiant_unique');
            // Unicité stricte du numéro au sein de l'épreuve : aucun doublon de numéro
            $table->unique(['epreuve_id', 'numero'], 'eval_comp_epreuve_numero_unique');

            $table->index(['evaluation_id', 'student_id']);
            $table->index(['evaluation_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_compostages');
    }
};
