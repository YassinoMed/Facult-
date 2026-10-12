<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Affectation : sous-groupe optionnel (TD/TP/projet, §11).
 * Rattachement validé serveur (le sous-groupe doit appartenir au groupe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affectations_groupe', function (Blueprint $table) {
            $table->foreignId('sous_groupe_id')->nullable()->after('groupe_id')
                ->constrained('sous_groupes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('affectations_groupe', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sous_groupe_id');
        });
    }
};
