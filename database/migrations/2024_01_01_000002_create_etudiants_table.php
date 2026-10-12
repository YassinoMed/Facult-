<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etudiants', function (Blueprint $table) {
            $table->id();
            $table->string('cin', 30)->unique();
            $table->string('num_inscription', 30)->unique();
            $table->string('nom', 100);
            $table->string('prenom', 100);
            $table->string('email')->unique();
            $table->string('telephone', 30)->nullable();
            $table->date('date_naissance')->nullable();
            $table->enum('sexe', ['M', 'F']);
            $table->foreignId('faculte_id')->constrained('facultes')->onDelete('cascade');
            $table->string('niveau', 20); // L1, L2, L3, M1, M2, D1, D2, D3
            $table->string('annee_academique', 20);
            $table->text('adresse');
            $table->string('ville', 100);
            $table->string('code_postal', 20);
            $table->string('annee_bac', 10);
            $table->string('mention_bac', 50)->nullable();
            $table->enum('statut', ['en_attente', 'valide', 'rejete'])->default('en_attente');
            $table->timestamps();

            // Indexations de performance
            $table->index(['nom', 'prenom']);
            $table->index('statut');
            $table->index('niveau');
            $table->index('faculte_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etudiants');
    }
};
