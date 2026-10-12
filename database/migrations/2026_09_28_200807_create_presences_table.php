<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module Attendance (§16/§32) : table dédiée `presences`, AUCUNE colonne
 * ajoutée aux tables centrales (users, etudiants). Contraintes :
 * - unicité (étudiant, date, séance) : un seul relevé par séance ;
 * - index (étudiant, date) : listes et taux sans full scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etudiant_id')->constrained('etudiants')->cascadeOnDelete();
            $table->date('date');
            $table->string('seance', 50)->nullable();
            $table->enum('statut', ['present', 'absent', 'retard', 'excuse']);
            $table->string('motif', 500)->nullable();
            $table->foreignId('enregistre_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['etudiant_id', 'date', 'seance']);
            $table->index(['etudiant_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presences');
    }
};
