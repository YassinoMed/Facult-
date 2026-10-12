<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOOP C (file de traitement, §7 automatisation) — migration additive :
 * codes des règles en échec du run de vérification, persistés pour
 * permettre la catégorisation DÉRIVÉE de la file (famille MENS/identité/
 * doublon/documents) sans ré-exécuter le moteur ni dupliquer ses règles.
 *
 * Nullable : les runs antérieurs restent lisibles (repli documenté sur
 * le verdict seul — BLOCKED si BLOQUANT, READY_FOR_REVIEW sinon).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dossier_verifications', function (Blueprint $table) {
            if (! Schema::hasColumn('dossier_verifications', 'regles_echouees')) {
                $table->json('regles_echouees')->nullable()->after('regles_reussies');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dossier_verifications', function (Blueprint $table) {
            if (Schema::hasColumn('dossier_verifications', 'regles_echouees')) {
                $table->dropColumn('regles_echouees');
            }
        });
    }
};
