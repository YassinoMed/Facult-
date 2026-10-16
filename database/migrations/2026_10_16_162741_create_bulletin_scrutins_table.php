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
        Schema::create('bulletin_scrutins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participation_scrutin_id')->constrained('participation_scrutins')->onDelete('cascade');
            $table->json('contenus_reponses'); // Mapping question_id -> réponse(s) - sans identifiant étudiant
            $table->timestamps();

            // Indexation
            $table->index('participation_scrutin_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bulletin_scrutins');
    }
};
