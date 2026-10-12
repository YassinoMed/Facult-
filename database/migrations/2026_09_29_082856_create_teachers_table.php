<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module Schedule : enseignants (entité propre — aucun rôle `users`
 * créé : l'authentification reste inchangée).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teachers', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 100);
            $table->string('prenom', 100);
            $table->string('email', 255)->nullable()->unique();
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->index(['nom', 'prenom']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teachers');
    }
};
