<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 10 — synchronisation d'émargement hors ligne.
 *
 * Journal ADDITIF et RÉVERSIBLE des lots reçus depuis la file locale de
 * l'agent. Il ne stocke AUCUNE donnée personnelle : uniquement des
 * identifiants techniques (épreuve, candidat), le statut pointé, la clé
 * d'idempotence et l'horodatage serveur.
 *
 * La contrainte unique (epreuve_id, cle_idempotence) garantit qu'un rejeu
 * réseau ne produit jamais un second pointage.
 *
 * Aucune colonne ajoutée aux tables centrales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emargements_hors_ligne', function (Blueprint $table) {
            $table->id();
            $table->foreignId('epreuve_id')->constrained('evaluation_epreuves')->cascadeOnDelete();
            $table->foreignId('candidat_id')->constrained('evaluation_candidats')->cascadeOnDelete();
            $table->string('cle_idempotence', 64);
            $table->string('statut', 30);
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recu_a');
            $table->timestamps();

            $table->unique(['epreuve_id', 'cle_idempotence']);
            $table->index(['candidat_id', 'recu_a']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emargements_hors_ligne');
    }
};
