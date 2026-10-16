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
        Schema::create('question_scrutins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scrutin_id')->constrained()->onDelete('cascade');
            $table->string('libelle', 500);
            $table->unsignedTinyInteger('type'); // 0=choix_unique, 1=choix_multiple, 2=oui_non, 3=texte_libre, 4=classement
            $table->integer('ordre');
            $table->text('consignes')->nullable();
            $table->boolean('obligatoire')->default(false);
            $table->unsignedTinyInteger('nb_options_min')->nullable(); // Pour choix multiple/classement
            $table->unsignedTinyInteger('nb_options_max')->nullable(); // Pour choix multiple
            $table->timestamps();

            // Indexations
            $table->index(['scrutin_id', 'ordre']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('question_scrutins');
    }
};
