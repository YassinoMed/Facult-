<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module Schedule : groupes/classes (liés à une faculté + niveau).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('nom', 100);
            $table->foreignId('faculte_id')->constrained('facultes')->restrictOnDelete();
            $table->string('niveau', 5);
            $table->string('filiere', 150)->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->index(['faculte_id', 'niveau']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groups');
    }
};
