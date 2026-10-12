<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Circuit de traitement (généré → approuvé → émis → envoyé) :
     * horodatage d'approbation, sans toucher aux statuts existants
     * (valide/remplace/revoque) ni aux flux historiques.
     */
    public function up(): void
    {
        Schema::table('generated_documents', function (Blueprint $table) {
            $table->timestamp('approuve_le')->nullable()->after('emis_par');
            $table->foreignId('approuve_par')->nullable()->after('approuve_le')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generated_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approuve_par');
            $table->dropColumn('approuve_le');
        });
    }
};
