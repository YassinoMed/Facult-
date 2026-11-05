<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOOP 32 — Persistance des campagnes de redoublement de masse (constat A3).
 *
 * Auparavant `campagne_id` était généré en mémoire puis perdu : aucune
 * corrélation possible entre un appel HTTP, son exécution et son bilan.
 * Cette table conserve la référence, les critères (sans PII), le statut
 * d'avancement et les agrégats du bilan.
 *
 * Migration strictement additive : aucune table existante n'est modifiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campagnes_redoublement', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('statut', 20)->default('preparee');
            $table->string('mode', 20)->default('simulation');

            $table->foreignId('annee_universitaire_id')->nullable()
                ->constrained('annees_universitaires')->nullOnDelete();
            $table->foreignId('programme_id')->nullable()
                ->constrained('programmes')->nullOnDelete();
            $table->foreignId('groupe_id')->nullable()
                ->constrained('groups')->nullOnDelete();
            $table->foreignId('faculte_id')->nullable()
                ->constrained('facultes')->nullOnDelete();

            $table->boolean('ignorer_alertes_mineures')->default(false);
            $table->unsignedInteger('dossiers_total')->default(0);
            $table->unsignedInteger('dossiers_traites')->default(0);

            // Agrégats uniquement : jamais de nom, CIN ni détail nominatif.
            $table->json('bilan')->nullable();
            $table->string('erreur', 255)->nullable();

            $table->foreignId('lancee_par')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamp('demarree_a')->nullable();
            $table->timestamp('terminee_a')->nullable();
            $table->timestamps();

            $table->index(['statut', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campagnes_redoublement');
    }
};
