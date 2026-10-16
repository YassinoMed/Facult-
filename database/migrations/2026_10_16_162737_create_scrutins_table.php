<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('scrutins', function (Blueprint $table) {
            $table->id();
            $table->string('titre', 200);
            $table->text('description')->nullable();
            $table->enum('etat', ['brouillon', 'programme', 'ouvert', 'clos', 'archive']);
            $table->dateTime('date_debut');
            $table->dateTime('date_fin');
            $table->boolean('resultats_autorises')->default(false);
            $table->unsignedTinyInteger('type_mode')->default(0); // 0=choix_unique, 1=choix_multiple, 2=oui_non, 3=texte_libre, 4=classement
            $table->unsignedTinyInteger('nb_reponses_max')->nullable(); // Pour choix multiple
            $table->unsignedTinyInteger('nb_reponses_min')->nullable(); // Pour choix multiple
            $table->timestamps();

            // Indexations
            $table->index(['etat', 'date_debut', 'date_fin']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scrutins');
    }
};
