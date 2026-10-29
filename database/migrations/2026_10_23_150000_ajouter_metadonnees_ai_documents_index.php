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
        if (Schema::hasTable('ai_documents_index')) {
            Schema::table('ai_documents_index', function (Blueprint $table) {
                if (! Schema::hasColumn('ai_documents_index', 'statut_document')) {
                    $table->string('statut_document', 30)->default('valide')->index(); // valide, brouillon, obsolete
                }
                if (! Schema::hasColumn('ai_documents_index', 'domaine_metier')) {
                    $table->string('domaine_metier', 50)->nullable()->index(); // inscriptions, lmd, examens, finances, gouvernance
                }
                if (! Schema::hasColumn('ai_documents_index', 'version_document')) {
                    $table->string('version_document', 30)->default('1.0');
                }
                if (! Schema::hasColumn('ai_documents_index', 'metadata')) {
                    $table->json('metadata')->nullable();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ai_documents_index')) {
            Schema::table('ai_documents_index', function (Blueprint $table) {
                $colonnes = array_filter([
                    Schema::hasColumn('ai_documents_index', 'metadata') ? 'metadata' : null,
                    Schema::hasColumn('ai_documents_index', 'version_document') ? 'version_document' : null,
                    Schema::hasColumn('ai_documents_index', 'domaine_metier') ? 'domaine_metier' : null,
                    Schema::hasColumn('ai_documents_index', 'statut_document') ? 'statut_document' : null,
                ]);

                if (! empty($colonnes)) {
                    $table->dropColumn($colonnes);
                }
            });
        }
    }
};
