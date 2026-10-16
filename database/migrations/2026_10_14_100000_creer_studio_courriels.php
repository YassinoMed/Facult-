<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Studio de modèles e-mail (LOOP-MAIL-TEMPLATE-STUDIO-03) : thèmes,
     * modèles, versions immuables et journal de cycle de vie. Aucune
     * donnée personnelle, aucun corps d'e-mail sensible imposé : les
     * schémas sont des structures validées, les variables sensibles
     * (code, token) sont interdites au registre.
     */
    public function up(): void
    {
        Schema::create('themes_courriel', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 100)->unique();
            $table->json('config');
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        Schema::create('modeles_courriel', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('nom', 150);
            $table->string('categorie', 40);
            $table->string('statut', 20)->default('brouillon');
            $table->unsignedInteger('version_courante')->nullable();
            $table->foreignId('theme_id')->nullable()->nullOnDelete()->constrained('themes_courriel');
            $table->boolean('verrou_systeme')->default(false);
            $table->timestamps();
            $table->index('statut');
            $table->index('categorie');
        });

        Schema::create('versions_modele_courriel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modele_id')->constrained('modeles_courriel')->cascadeOnDelete();
            $table->unsignedInteger('numero');
            $table->json('schema');
            $table->unsignedBigInteger('auteur_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->unique(['modele_id', 'numero']);
        });

        Schema::create('journal_modeles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modele_id')->nullable()->nullOnDelete()->constrained('modeles_courriel');
            $table->unsignedInteger('version_numero')->nullable();
            $table->string('action', 30);
            $table->unsignedBigInteger('acteur_id')->nullable();
            $table->json('detail')->nullable();
            $table->timestamps();
            $table->index(['modele_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_modeles');
        Schema::dropIfExists('versions_modele_courriel');
        Schema::dropIfExists('modeles_courriel');
        Schema::dropIfExists('themes_courriel');
    }
};
