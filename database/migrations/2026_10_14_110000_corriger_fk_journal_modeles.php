<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Correctif : la FK `journal_modeles.modele_id` doit être
     * `ON DELETE SET NULL` (audit du journal préservé lors de la
     * suppression d'un brouillon jamais publié).
     */
    public function up(): void
    {
        Schema::table('journal_modeles', function (Blueprint $table) {
            $table->dropForeign(['modele_id']);
        });

        Schema::table('journal_modeles', function (Blueprint $table) {
            $table->foreign('modele_id')->references('id')->on('modeles_courriel')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journal_modeles', function (Blueprint $table) {
            $table->dropForeign(['modele_id']);
        });

        Schema::table('journal_modeles', function (Blueprint $table) {
            $table->foreign('modele_id')->references('id')->on('modeles_courriel');
        });
    }
};
