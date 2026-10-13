<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Configurations SMTP nommées (ORM Phase C) : secrets chiffrés via
     * le cast `encrypted` du modèle (jamais en clair). Une seule active.
     */
    public function up(): void
    {
        Schema::create('smtp_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 100);
            $table->string('hote', 255);
            $table->unsignedSmallInteger('port')->default(587);
            $table->string('chiffrement', 10)->default('tls');
            $table->string('identifiant', 255)->nullable();
            $table->text('mot_de_passe')->nullable();
            $table->string('expediteur', 255);
            $table->string('nom_expediteur', 255)->nullable();
            $table->boolean('actif')->default(false);
            $table->timestamps();
            $table->index('actif');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smtp_configurations');
    }
};
