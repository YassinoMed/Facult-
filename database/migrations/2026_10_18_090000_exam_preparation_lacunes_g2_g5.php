<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOOP-EXAM-PREPARATION-01 — comblement des lacunes G2-G5 :
 *
 * G5 : `numero_anonyme` — identifiant stable par candidat et par épreuve
 *      (jamais la CIN), structure prête pour un futur code-barres de copie.
 * G4 : `population_*` — snapshot de la population au moment de la
 *      validation de la liste (hash + effectif + horodatage) pour signaler
 *      les changements ultérieurs sans figer les inscriptions.
 * G3 : `evaluation_documents_generes` — historique des générations PDF
 *      (émargement par épreuve et par salle) : auteur, effectif, fichier —
 *      base de l'aperçu, de la réimpression et de la traçabilité.
 *
 * Additif strict : nullable, aucune donnée existante modifiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_candidats', function (Blueprint $table) {
            $table->string('numero_anonyme', 32)->nullable()->after('inscription_pedagogique_id');
            $table->unique(['epreuve_id', 'numero_anonyme'], 'uq_candidat_numero_anonyme');
        });

        Schema::table('evaluation_epreuves', function (Blueprint $table) {
            $table->dateTime('population_validated_at')->nullable()->after('capacite_necessaire');
            $table->string('population_hash', 64)->nullable()->after('population_validated_at');
            $table->unsignedInteger('population_effectif')->nullable()->after('population_hash');
        });

        Schema::create('evaluation_documents_generes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('epreuve_id')->constrained('evaluation_epreuves')->cascadeOnDelete();
            $table->foreignId('salle_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->string('type', 32)->default('emargement');
            $table->foreignId('genere_par')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('candidats_count')->default(0);
            $table->string('nom_fichier', 160);
            $table->timestamps();
            $table->index(['epreuve_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_documents_generes');

        Schema::table('evaluation_epreuves', function (Blueprint $table) {
            $table->dropColumn(['population_validated_at', 'population_hash', 'population_effectif']);
        });

        Schema::table('evaluation_candidats', function (Blueprint $table) {
            $table->dropUnique('uq_candidat_numero_anonyme');
            $table->dropColumn('numero_anonyme');
        });
    }
};
