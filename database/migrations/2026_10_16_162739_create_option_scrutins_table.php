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
        Schema::create('option_scrutins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_scrutin_id')->constrained('question_scrutins')->onDelete('cascade');
            $table->string('libelle', 300);
            $table->string('code', 50)->nullable(); // Code pour classement/identification
            $table->integer('ordre');
            $table->boolean('est_texte_libre')->default(false);
            $table->timestamps();

            // Indexations
            $table->index(['question_scrutin_id', 'ordre']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('option_scrutins');
    }
};
