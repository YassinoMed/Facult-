<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Correspondance CONTRÔLÉE des lieux de naissance arabes → latins
     * (§14) : table administrative extensible (arabe | latin |
     * officiel | pays). La clé arabe normalisée permet la recherche
     * quelles que soient les variantes d'écriture (diacritiques…).
     */
    public function up(): void
    {
        Schema::create('lieux_naissance', function (Blueprint $table) {
            $table->id();
            $table->string('arabic_name', 150);              // forme d'affichage (arabe)
            $table->string('arabic_name_key', 150);          // forme NORMALISÉE (recherche)
            $table->string('latin_name', 150);              // translittération usuelle
            $table->string('official_name', 150);           // dénomination officielle
            $table->string('country', 100)->default('Tunisie');
            $table->timestamps();

            $table->unique('arabic_name_key');
            $table->index(['country', 'latin_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lieux_naissance');
    }
};
