<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOOP 42 — traçabilité MENS : `EtudiantDefinition::persister()`
 * réconcilie déjà `mens.matricule_national` vers
 * `identifiant_national`, mais la colonne n'existait pas (donnée
 * perdue silencieusement). Ajout nullable + index simple, sans
 * unique (unicité source MENS non prouvée).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etudiants', function (Blueprint $table) {
            if (! Schema::hasColumn('etudiants', 'identifiant_national')) {
                $table->string('identifiant_national', 30)->nullable()->after('cin');
                $table->index('identifiant_national');
            }
        });
    }

    public function down(): void
    {
        Schema::table('etudiants', function (Blueprint $table) {
            if (Schema::hasColumn('etudiants', 'identifiant_national')) {
                $table->dropIndex(['identifiant_national']);
                $table->dropColumn('identifiant_national');
            }
        });
    }
};
