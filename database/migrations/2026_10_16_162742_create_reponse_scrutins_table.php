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
        Schema::create('reponse_scrutins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bulletin_scrutin_id')->constrained('bulletin_scrutins')->onDelete('cascade');
            $table->foreignId('question_scrutin_id')->constrained('question_scrutins')->onDelete('cascade');
            $table->foreignId('option_scrutin_id')->nullable()->constrained('option_scrutins')->nullOnDelete();
            $table->string('texte_libre')->nullable();
            $table->integer('rang_classement')->nullable(); // Pour le type classement
            $table->timestamps();

            // Indexations pour calcul de résultats
            $table->index(['bulletin_scrutin_id', 'question_scrutin_id']);
            $table->index(['question_scrutin_id', 'option_scrutin_id']);
            $table->index('rang_classement');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reponse_scrutins');
    }
};
