<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dossier académique (archive) d'un étudiant — un seul dossier par étudiant.
     */
    public function up(): void
    {
        Schema::create('archives_etudiants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etudiant_id')
                ->unique()
                ->constrained('etudiants')
                ->onDelete('cascade');
            $table->string('matricule', 30)->unique();
            $table->date('date_archivage');
            $table->enum('statut', ['actif', 'clos'])->default('actif');
            $table->text('observation')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archives_etudiants');
    }
};
