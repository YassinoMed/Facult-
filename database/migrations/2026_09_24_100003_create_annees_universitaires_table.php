<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annees_universitaires', function (Blueprint $table) {
            $table->id();
            $table->string('libelle', 20)->unique(); // ex : 2025-2026
            $table->date('date_debut');
            $table->date('date_fin');
            $table->boolean('active')->default(false);
            $table->timestamps();

            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annees_universitaires');
    }
};
