<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Phase 7 §53 : cycle de vie des documents officiels
        // (valide → remplace/révoque, jamais de suppression).
        Schema::table('generated_documents', function (Blueprint $table) {
            $table->string('statut', 20)->default('valide')->after('version');
            $table->index(['student_dossier_id', 'type', 'statut']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generated_documents', function (Blueprint $table) {
            $table->dropIndex(['student_dossier_id', 'type', 'statut']);
            $table->dropColumn('statut');
        });
    }
};
