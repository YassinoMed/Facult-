<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module StudentDossiers (§1) : dossier administratif permanent, UN par
 * étudiant — jamais de duplication des données personnelles (elles
 * restent dans `etudiants`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_dossiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etudiant_id')->unique()->constrained('etudiants')->cascadeOnDelete();
            $table->string('reference', 30)->unique();
            $table->enum('status', ['open', 'complete', 'archived'])->default('open');
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_dossiers');
    }
};
