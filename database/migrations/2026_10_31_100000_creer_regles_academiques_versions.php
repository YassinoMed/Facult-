<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOOP 44 — Gouvernance versionnée des règles académiques.
 *
 * Table additive : aucune table existante n'est modifiée. Les règles elles-mêmes
 * ne sont pas seedées ici : une version doit être créée par un acteur habilité,
 * avec une référence officielle, puis approuvée par un second acteur.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('regles_academiques_versions')) {
            return;
        }

        Schema::create('regles_academiques_versions', function (Blueprint $table) {
            $table->id();

            // Code fonctionnel de la règle (ex. seuil de compensation d'une
            // formation). Aucun code n'est imposé par le socle.
            $table->string('code', 120)->index();

            // Version strictement croissante par code.
            $table->unsignedInteger('version');

            // BROUILLON → EN_REVUE → APPROUVEE → PUBLIEE → RETIREE
            $table->string('statut', 20)->default('BROUILLON')->index();

            // Contenu de la règle : structure libre, validée par le service.
            $table->json('contenu');

            // Référence du texte / de la décision qui fonde la règle.
            // Obligatoire pour l'approbation et la publication.
            $table->string('reference_officielle', 255)->nullable();

            // Période d'application. `date_effet` obligatoire à la publication.
            $table->date('date_effet')->nullable();
            $table->date('date_fin')->nullable();

            // Empreinte SHA-256 du contenu canonique : détecte toute
            // altération même si le JSON est modifié en base.
            $table->string('checksum', 64);

            // Traçabilité des acteurs (identifiants techniques uniquement).
            $table->foreignId('cree_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approuve_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('publie_par_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('retire_par_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('approuve_le')->nullable();
            $table->timestamp('publie_le')->nullable();
            $table->timestamp('retire_le')->nullable();
            $table->text('motif_retrait')->nullable();

            $table->timestamps();

            $table->unique(['code', 'version']);
            $table->index(['code', 'statut', 'date_effet']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regles_academiques_versions');
    }
};
