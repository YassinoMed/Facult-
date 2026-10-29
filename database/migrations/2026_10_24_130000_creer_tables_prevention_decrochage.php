<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 15 — Prévention du décrochage (accompagnement humain).
 *
 * Tables ADDITIVES et RÉVERSIBLES du module uniquement :
 * - `regles_decrochage` : catalogue VERSIONNÉ de règles explicables,
 *   création en `brouillon`, activation seulement après APPROBATION
 *   traçée (jamais une règle instituteuse au silence).
 * - `alertes_decrochage` : signal généré à partir d'une règle approuvée,
 *   JAMAIS une modification de note ou de statut d'inscription ; flux
 *   humain generee → examinee → cloturee, entièrement tracé.
 * - `suivis_decrochage` : mesures d'accompagnement, DISTINCTES des
 *   résultats officiels (aucune FK vers notes/suivi académique).
 *
 * Aucune colonne ajoutée aux tables centrales (règle modules du dépôt).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regles_decrochage', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('nom', 150);
            $table->string('type', 30); // presences | notes
            $table->string('mesure', 40); // absents | retards | notes_sous_seuil
            $table->decimal('seuil', 8, 2); // seuil de DÉCLENCHEMENT (valeur de la mesure)
            // Seuil de la mesure sous-jacente (notes : note < seuil_mesure compte).
            $table->decimal('seuil_mesure', 8, 2)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('statut', 20)->default('brouillon'); // brouillon | approuvee | desactivee
            $table->text('raison_explicable')->nullable(); // OBLIGATOIRE avant approbation
            $table->foreignId('approuve_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approuve_le')->nullable();
            $table->foreignId('desactivee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'statut']);
        });

        Schema::create('alertes_decrochage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('regle_id')->constrained('regles_decrochage')->restrictOnDelete();
            $table->foreignId('etudiant_id')->constrained('etudiants')->restrictOnDelete();
            $table->foreignId('annee_universitaire_id')->nullable()->constrained('annees_universitaires')->nullOnDelete();
            $table->decimal('valeur', 10, 2);
            $table->decimal('seuil', 10, 2);
            $table->json('preuve'); // sources datées : ids, dates, extraits
            $table->string('statut', 20)->default('generee'); // generee | examinee | cloturee
            $table->text('commentaire_examen')->nullable();
            $table->foreignId('examen_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('examine_le')->nullable();
            $table->text('motif_cloture')->nullable();
            $table->foreignId('cloturee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cloturee_le')->nullable();
            $table->timestamp('retention_a')->nullable(); // politique non confirmée : reste NULL
            $table->timestamps();

            $table->index(['regle_id', 'etudiant_id', 'statut']);
            $table->index('statut');
        });

        Schema::create('suivis_decrochage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alerte_id')->constrained('alertes_decrochage')->restrictOnDelete();
            $table->text('mesure');
            // nullOnDelete : la mesure d'accompagnement SURVIT à la
            // suppression du compte (traçabilité, pattern du dépôt).
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suivis_decrochage');
        Schema::dropIfExists('alertes_decrochage');
        Schema::dropIfExists('regles_decrochage');
    }
};
