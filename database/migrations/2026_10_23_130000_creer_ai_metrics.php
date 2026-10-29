<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODULE AI-ASSISTANT (Lot 11) — métriques NON sensibles.
 * Aucun contenu de prompt, aucune réponse, aucune PII : uniquement des
 * métadonnées d'exploitation (fournisseur, modèle, latence, jetons,
 * outil, état, code d'erreur public).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_metrics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id')->nullable()->index();
            $table->unsignedBigInteger('utilisateur_id')->nullable()->index();
            $table->string('fournisseur', 40);
            $table->string('modele', 80);
            $table->string('outil', 60)->nullable();
            $table->string('etat', 20); // ok | echec | refuse | plafond.
            $table->string('code_erreur', 40)->nullable(); // code public uniquement.
            $table->unsignedInteger('latence_ms')->nullable();
            $table->unsignedInteger('jetons_entree')->nullable();
            $table->unsignedInteger('jetons_sortie')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_metrics');
    }
};
