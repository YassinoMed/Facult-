<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 20 — snapshots de rapport de couverture pédagogique.
 *
 * Table ADDITIVE et RÉVERSIBLE : chaque ligne est une trace figée d'un
 * rapport calculé. Aucune colonne ajoutée aux tables existantes.
 * L'immutabilité est portée par le service (aucun update/delete) ; la
 * clé étrangère `maquette_id` empêche un snapshot orphelin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competence_rapport_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maquette_id')->constrained('maquettes')->cascadeOnDelete();
            $table->string('empreinte', 64); // sha256 du rapport canonisé
            $table->json('donnees'); // rapport complet tel que publié
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->index(['maquette_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competence_rapport_snapshots');
    }
};
