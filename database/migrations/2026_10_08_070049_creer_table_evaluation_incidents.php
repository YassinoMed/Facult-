<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOOP PARTIE EXAMENS — incidents d'épreuve : observations post-hoc
 * (fraude, matériel, retard…), jamais bloquées par le verrou épreuve
 * (le verrou protège les notes, pas le constat). Aucune PII dans
 * l'audit : les événements ne portent que des identifiants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_incidents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('epreuve_id')->constrained('evaluation_epreuves')->cascadeOnDelete();
            $table->foreignId('etudiant_id')->nullable()->constrained('etudiants')->nullOnDelete();
            $table->string('type', 30);
            $table->string('statut', 20)->default('signale');
            $table->text('description');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['epreuve_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_incidents');
    }
};
