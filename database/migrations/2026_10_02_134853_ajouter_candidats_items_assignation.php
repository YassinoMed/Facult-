<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matrice candidats par étudiant (§6-7/§20) : détail complet des groupes
 * évalués (compatible + motif + places restantes) + flexibilité
 * (nombre de compatibles, filtrable) sur l'item existant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignment_items', function (Blueprint $table) {
            $table->json('candidats')->nullable()->after('alternatives');
            $table->unsignedTinyInteger('flexibilite')->default(0)->after('candidats');
            $table->index(['simulation_id', 'flexibilite']);
        });
    }

    public function down(): void
    {
        Schema::table('assignment_items', function (Blueprint $table) {
            $table->dropIndex(['simulation_id', 'flexibilite']);
            $table->dropColumn(['candidats', 'flexibilite']);
        });
    }
};
