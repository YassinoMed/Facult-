<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('enseignement_salle')) {
            Schema::create('enseignement_salle', function (Blueprint $table) {
                $table->id();
                $table->foreignId('enseignement_id')->constrained('enseignements')->cascadeOnDelete();
                $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['enseignement_id', 'room_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('enseignement_salle');
    }
};
