<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 18 §18.1 : index de recherche universelle. Moteur SGBD natif
 * (choix documenté : zéro dépendance externe, fonctionne SQLite/MySQL ;
 * FULLTEXT exploité côté MySQL via index dédié, repli LIKE sinon).
 * Le filtrage sécurité (scope/visibilité) est stocké dans l'index pour
 * être appliqué DANS la requête, jamais après coup (§18.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('search_documents')) {
            Schema::create('search_documents', function (Blueprint $table) {
                $table->id();
                $table->string('type_entite', 60)->index();
                $table->unsignedBigInteger('entite_id')->index();
                $table->string('titre', 255);
                $table->string('sous_titre', 255)->nullable();
                $table->text('corps_normalise')->nullable();
                $table->string('mots_cles', 500)->nullable();
                $table->string('identifiants', 500)->nullable()->index();
                $table->string('locale', 10)->default('fr');
                $table->unsignedBigInteger('faculte_id')->nullable()->index();
                $table->string('programme_code', 60)->nullable()->index();
                $table->string('visibilite', 60)->default('auth')->index();
                $table->string('permission_requise', 80)->nullable()->index();
                $table->unsignedInteger('priorite')->default(0);
                $table->timestamp('entite_maj_le')->nullable();
                $table->timestamps();
                $table->unique(['type_entite', 'entite_id']);
                $table->index(['type_entite', 'faculte_id']);

                if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'pgsql'], true)) {
                    $table->fullText(['titre', 'sous_titre', 'corps_normalise', 'mots_cles'], 'search_docs_ft');
                } else {
                    $table->index(['titre']);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('search_documents');
    }
};
