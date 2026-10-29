<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 20 — Programmes et compétences : extension du référentiel LMD.
 *
 * Tables ADDITIVES et RÉVERSIBLES du module uniquement :
 * - `competences` : catalogue versionné SAISI par l'administration
 *   (jamais seedé — inventer des compétences est interdit). Une compétence
 *   APPROUVÉE est figée ; son champ `version` trace les révisions du
 *   brouillon avant approbation ; toute évolution = archivage + nouvelle
 *   compétence (jamais de réinterprétation rétroactive).
 * - `competence_objectifs` : associations canoniques programme/UE/ECUE ↔
 *   compétence avec niveau attendu SAISI (aucune taxonomie inventée).
 *   Les associations portent sur les entités CANONIQUES du référentiel,
 *   jamais sur une version de maquette : une nouvelle version de maquette
 *   ne modifie donc jamais les associations ni les rapports passés.
 *
 * Aucune colonne ajoutée aux tables existantes (règle modules du dépôt).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competences', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('libelle', 200);
            $table->text('description')->nullable();
            $table->foreignId('faculte_id')->nullable()->constrained('facultes')->restrictOnDelete(); // null = portée établissement
            $table->string('statut', 20)->default('brouillon'); // brouillon | approuvee | archivee
            $table->unsignedSmallInteger('version')->default(1); // révisions du brouillon, figées à l'approbation
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approuve_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approuve_le')->nullable();
            $table->timestamps();

            $table->index(['statut', 'faculte_id']);
        });

        Schema::create('competence_objectifs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competence_id')->constrained('competences')->restrictOnDelete();
            $table->string('cible_type', 12); // programme | ue | ecue (entités canoniques du référentiel)
            $table->unsignedBigInteger('cible_id'); // existence vérifiée par le service (jamais de lien mort)
            $table->string('niveau_attendu', 60)->nullable(); // saisi librement par l'autorité, jamais imposé
            $table->string('commentaire', 500)->nullable();
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['competence_id', 'cible_type', 'cible_id']); // idempotence
            $table->index(['cible_type', 'cible_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competence_objectifs');
        Schema::dropIfExists('competences');
    }
};
