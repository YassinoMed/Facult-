<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform Sentinel — registre des règles (surcharges) + incidents
 * (cycle de vie, déduplication par cle_unique). Tables dédiées au
 * module, aucune colonne en table centrale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sentinel_regles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('categorie', 40);
            $table->string('gravite', 20)->default('avertissement');
            $table->string('module', 60)->nullable();
            $table->boolean('actif')->default(true);
            $table->json('seuil')->nullable();
            $table->timestamps();

            $table->index(['actif', 'categorie']);
        });

        Schema::create('sentinel_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('cle_unique', 64)->unique();
            $table->string('code', 60)->index();
            $table->string('categorie', 40);
            $table->string('gravite', 20)->index();
            $table->string('module', 60)->nullable();
            $table->string('titre', 255);
            $table->text('diagnostic');
            $table->text('recommandation')->nullable();
            $table->string('statut', 20)->default('ouvert')->index();
            $table->string('entite_type', 60)->nullable();
            $table->unsignedBigInteger('entite_id')->nullable();
            $table->string('entite_libelle', 255)->nullable();
            $table->foreignId('faculte_id')->nullable()->constrained('facultes')->nullOnDelete();
            $table->dateTime('detecte_le');
            $table->dateTime('derniere_detection_le');
            $table->dateTime('derniere_notification_le')->nullable();
            $table->dateTime('resolu_le')->nullable();
            $table->unsignedInteger('compteur')->default(1);
            $table->timestamps();

            $table->index(['statut', 'gravite']);
            $table->index(['entite_type', 'entite_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sentinel_incidents');
        Schema::dropIfExists('sentinel_regles');
    }
};
