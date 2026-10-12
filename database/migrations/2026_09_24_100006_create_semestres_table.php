<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('semestres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inscription_academique_id')
                ->constrained('inscriptions_academiques')
                ->onDelete('cascade');
            $table->unsignedTinyInteger('numero'); // 1 ou 2
            $table->decimal('moyenne', 5, 2)->nullable();
            $table->unsignedSmallInteger('credits_obtenus')->default(0);
            $table->unsignedSmallInteger('credits_total')->default(0);
            $table->string('decision', 100)->nullable();
            $table->timestamps();

            $table->unique(['inscription_academique_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('semestres');
    }
};
