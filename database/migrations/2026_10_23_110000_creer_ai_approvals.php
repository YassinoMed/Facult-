<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODULE AI-ASSISTANT (Lot 9) — workflow d'approbation humaine.
 * Migration ADDITIVE et réversible ; aucune donnée existante modifiée.
 * Estampe 110000 : APRES la migration canonique des tables
 * ai_conversations / ai_messages (lot concurrent, 2026_10_23_100000).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_approvals', function (Blueprint $table) {
            $table->id();
            $table->uuid('identifiant')->unique();
            $table->unsignedBigInteger('demandeur_id')->index();
            $table->unsignedBigInteger('approbateur_id')->nullable()->index();
            $table->unsignedBigInteger('conversation_id')->nullable()->index();
            $table->string('action', 60); // clé dans la liste blanche des exécuteurs.
            $table->string('description', 255);
            $table->json('cibles')->nullable(); // identifiants des ressources ciblées.
            $table->json('parametres')->nullable();
            $table->text('impacts_resume');
            $table->string('niveau_risque', 20); // faible | modere | eleve.
            $table->timestamp('expire_le');
            $table->string('etat', 20); // pending_approval | approved | rejected | expired | executing | completed | failed | cancelled.
            $table->string('empreinte', 64); // sha256 détection de falsification.
            $table->json('journal')->nullable(); // décisions horodatées (sans PII).
            $table->timestamp('decide_le')->nullable();
            $table->string('resultat', 255)->nullable();
            $table->timestamps();

            $table->index(['etat', 'expire_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_approvals');
    }
};
