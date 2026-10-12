<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refonte affectation : verrouillage explicite + traçabilité de la
 * confirmation sur la table existante (amélioration, pas de doublon).
 * `verrouille` = jamais déplaçable par simulation ; `confirme_*` =
 * confirmation issue d'une simulation (contrainte pour les suivantes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affectations_groupe', function (Blueprint $table) {
            $table->boolean('verrouille')->default(false)->after('statut');
            $table->timestamp('confirme_le')->nullable()->after('verrouille');
            $table->foreignId('confirme_par')->nullable()->after('confirme_le')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('affectations_groupe', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirme_par');
            $table->dropColumn(['verrouille', 'confirme_le']);
        });
    }
};
