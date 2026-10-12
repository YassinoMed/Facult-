<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matieres', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('nom', 150);
            $table->decimal('coefficient', 4, 2)->default(1);
            $table->unsignedSmallInteger('credits')->default(2);
            $table->string('niveau', 10); // L1, L2, L3, M1, M2
            $table->string('filiere', 150)->nullable(); // null = tronc commun
            $table->unsignedTinyInteger('semestre'); // 1 ou 2
            $table->timestamps();

            $table->index(['niveau', 'semestre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matieres');
    }
};
