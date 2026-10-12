<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel MENS (mission inscription intelligente) : recopie
 * interrogeable du fichier officiel, JAMAIS une écriture directe dans
 * le dossier local (non-écrasement §5). Traçabilité : hash de ligne +
 * auteur + date ; versionnement via ImportReport (fichier, auteur,
 * référence externe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mens_references', function (Blueprint $table) {
            $table->id();
            $table->string('cin_normalise', 30);
            $table->string('cin_original', 30)->nullable();
            $table->date('date_naissance')->nullable();
            $table->string('nom', 100)->nullable();
            $table->string('prenom', 100)->nullable();
            $table->string('nom_ar', 100)->nullable();
            $table->string('prenom_ar', 100)->nullable();
            $table->string('sexe', 1)->nullable();
            $table->string('nationalite', 50)->nullable();
            $table->string('matricule_national', 30)->nullable();
            $table->string('etablissement', 150)->nullable();
            $table->string('annee', 20);
            $table->string('programme_code', 30)->nullable();
            $table->string('niveau', 10)->nullable();
            $table->string('source_hash', 64);
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique(['cin_normalise', 'annee'], 'uniq_mens_cin_annee');
            $table->index('cin_normalise');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mens_references');
    }
};
