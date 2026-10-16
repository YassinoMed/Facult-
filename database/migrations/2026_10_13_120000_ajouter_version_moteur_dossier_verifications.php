<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOOP consolidation inscription (C4) — traçabilité du moteur de vérification :
 * version des règles + liste des règles exécutées persistées sur chaque run
 * (reproductibilité des résultats dans le temps).
 *
 * Migration strictement additive et réversible : colonnes nullables, les runs
 * antérieurs restent lisibles (version inconnue = interprétation prudente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossier_verifications', function (Blueprint $table) {
            if (! Schema::hasColumn('dossier_verifications', 'moteur_version')) {
                $table->string('moteur_version', 20)->nullable()->after('source');
            }
            if (! Schema::hasColumn('dossier_verifications', 'regles_executees')) {
                $table->json('regles_executees')->nullable()->after('regles_echouees');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dossier_verifications', function (Blueprint $table) {
            if (Schema::hasColumn('dossier_verifications', 'regles_executees')) {
                $table->dropColumn('regles_executees');
            }
            if (Schema::hasColumn('dossier_verifications', 'moteur_version')) {
                $table->dropColumn('moteur_version');
            }
        });
    }
};
