<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Journal d'audit académique — immuable (created_at uniquement).
     * Ne jamais y écrire : mots de passe, tokens, cookies, secrets.
     */
    public function up(): void
    {
        Schema::create('historique_academique', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etudiant_id')
                ->constrained('etudiants')
                ->onDelete('cascade');
            $table->foreignId('utilisateur_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('action', 50);
            $table->string('entite', 50);
            $table->unsignedBigInteger('entite_id')->nullable();
            $table->text('ancienne_valeur')->nullable();
            $table->text('nouvelle_valeur')->nullable();
            $table->string('adresse_ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['etudiant_id', 'created_at']);
            $table->index(['action', 'entite']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historique_academique');
    }
};
