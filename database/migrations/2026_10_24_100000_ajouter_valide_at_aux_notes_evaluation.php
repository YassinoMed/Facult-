<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOT 05 — Auditabilité des notes (_audit directeur LOOP-00 §F5) : la
 * colonne `valide_at` n'existait pas — l'horodatage de la validation
 * n'était conservé nulle part et le drapeau d'audit
 * `a_ete_modifiee_apres_validation` était systématiquement faux.
 *
 * Migration strictement additive : aucune donnée existante rétrodatée
 * (les notes antérieures restent sans horodatage = drapeau faux jusqu'à
 * une revalidation explicite).
 *
 * Stratégie de retour arrière : `php artisan migrate:rollback --step=1`
 * (sans effet de bord : la colonne est nullable et jamais lue comme
 * obligatoire).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_notes', function (Blueprint $table): void {
            $table->dateTime('valide_at')->nullable()->after('valide_par')
                ->comment('Horodatage de la DERNIÈRE validation (révalidation écrase).');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_notes', function (Blueprint $table): void {
            $table->dropColumn('valide_at');
        });
    }
};
