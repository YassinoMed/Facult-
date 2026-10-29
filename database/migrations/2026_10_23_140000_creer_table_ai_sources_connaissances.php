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
        if (! Schema::hasTable('ai_sources_connaissances')) {
            Schema::create('ai_sources_connaissances', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('nom', 150);
                $table->text('description')->nullable();
                $table->string('type', 50)->index(); // project_code, internal_document, technical_documentation, business_procedure, loop_report, approved_web_source
                $table->string('origine', 255)->unique(); // chemin de dossier, fichier ou URL autorisée
                $table->string('statut', 30)->default('actif')->index(); // actif, suspendu, erreur
                $table->string('politique_actualisation', 30)->default('sur_modification'); // manuelle, sur_modification, planifiee
                $table->timestamp('derniere_synchronisation_a')->nullable()->index();
                $table->text('derniere_erreur')->nullable();
                $table->string('derniere_empreinte', 64)->nullable();
                $table->unsignedInteger('nombre_documents')->default(0);
                $table->unsignedInteger('nombre_morceaux')->default(0);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['type', 'statut']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_sources_connaissances');
    }
};
