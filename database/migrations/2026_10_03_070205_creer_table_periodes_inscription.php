<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOOP périodes d'inscription : UNE table pour périodes normales ET
 * ouvertures exceptionnelles (portée nullable = tous). L'historique
 * n'est jamais réécrit : une exception est une ligne dédiée, jamais
 * une extension silencieuse des dates normales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('annee_universitaire_id')->constrained('annees_universitaires')->onDelete('restrict');
            $table->foreignId('faculte_id')->nullable()->constrained('facultes')->nullOnDelete();
            $table->foreignId('programme_id')->nullable()->constrained('programmes')->nullOnDelete();
            $table->string('niveau', 10)->nullable();
            $table->string('type_inscription', 20)->nullable();
            $table->enum('type_periode', ['normale', 'exception'])->default('normale');
            $table->foreignId('etudiant_id')->nullable()->constrained('etudiants')->onDelete('cascade');
            $table->dateTime('opened_at');
            $table->dateTime('closed_at');
            $table->text('motif')->nullable();
            $table->enum('statut', ['active', 'revoquee', 'utilisee'])->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('used_at')->nullable();
            $table->timestamps();

            $table->index(['annee_universitaire_id', 'faculte_id', 'statut'], 'idx_periodes_annee_faculte');
            $table->index(['etudiant_id', 'statut'], 'idx_periodes_etudiant');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_periods');
    }
};
