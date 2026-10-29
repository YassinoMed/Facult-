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
        if (! Schema::hasTable('ai_documents_index')) {
            Schema::create('ai_documents_index', function (Blueprint $table) {
                $table->id();
                $table->string('source', 100);
                $table->string('titre', 255);
                $table->string('chemin_fichier', 255);
                $table->unsignedInteger('index_morceau')->default(0);
                $table->text('contenu_morceau');
                $table->string('empreinte_sha256', 64)->index();
                $table->unsignedInteger('version_indexeur')->default(1);
                $table->boolean('est_sensible')->default(false)->index();
                $table->string('permission_requise', 100)->nullable()->index();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['chemin_fichier', 'index_morceau']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_documents_index');
    }
};
