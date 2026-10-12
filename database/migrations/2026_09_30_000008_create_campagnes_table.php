<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 15 — Campagnes de communication : envois groupés planifiés,
 * idempotents (cle_unique), statistiques d'envoi et de lecture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campagnes', function (Blueprint $table) {
            $table->id();
            $table->string('titre', 150);
            $table->text('message');
            $table->string('audience', 20)->default('etudiants');
            $table->foreignId('faculte_id')->nullable()->constrained('facultes')->restrictOnDelete();
            $table->foreignId('programme_id')->nullable()->constrained('programmes')->restrictOnDelete();
            $table->foreignId('groupe_id')->nullable()->constrained('groups')->restrictOnDelete();
            $table->string('statut', 20)->default('brouillon');
            $table->string('cle_unique', 120)->unique();
            $table->timestamp('planifiee_a')->nullable();
            $table->timestamp('envoyee_a')->nullable();
            $table->unsignedInteger('destinataires_estimes')->default(0);
            $table->unsignedInteger('envoyes')->default(0);
            $table->unsignedInteger('lus')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['statut', 'planifiee_a']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campagnes');
    }
};
