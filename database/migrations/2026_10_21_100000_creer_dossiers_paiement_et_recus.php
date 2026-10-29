<?php

use App\Modules\Finances\Models\DossierPaiement;
use App\Modules\Finances\Models\RecuPaiement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOOP-UNIREGISTRE-INSCRIPTIONS-PAIEMENTS-01 : dossier de paiement
 * annuel et reçus déposés par l'étudiant.
 *
 * Additif : aucune colonne existante n'est modifiée, aucune donnée
 * historique n'est réécrite. Le booléen historique `inscriptions_academiques.paye`
 * reste un indicateur externe ; il n'est jamais alimenté par un simple dépôt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossiers_paiement', function (Blueprint $table) {
            $table->id();

            // Une inscription annuelle = un seul dossier de paiement.
            $table->foreignId('inscription_academique_id')
                ->unique()
                ->constrained('inscriptions_academiques')
                ->cascadeOnDelete();

            $table->enum('statut', DossierPaiement::STATUTS)
                ->default(DossierPaiement::NON_PAYE);

            $table->foreignId('pris_en_charge_par')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('pris_en_charge_le')->nullable();

            $table->foreignId('decide_par')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('decide_le')->nullable();

            $table->timestamps();

            $table->index('statut');
        });

        Schema::create('recus_paiement', function (Blueprint $table) {
            $table->id();

            $table->foreignId('dossier_paiement_id')
                ->constrained('dossiers_paiement')
                ->cascadeOnDelete();

            // Double rattachement volontaire : le reçu reste lisible même
            // si l'on interroge directement l'inscription ou l'année.
            $table->foreignId('inscription_academique_id')
                ->constrained('inscriptions_academiques')
                ->cascadeOnDelete();
            $table->foreignId('annee_universitaire_id')
                ->constrained('annees_universitaires')
                ->restrictOnDelete();

            // Version croissante par dossier : un refus n'écrase jamais
            // l'historique, un nouveau reçu crée la version suivante.
            $table->unsignedInteger('version');

            // Métadonnées du fichier privé (jamais d'URL publique).
            $table->string('storage_path');
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->string('extension', 10);
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);

            // Instantané des règles officielles au moment du dépôt.
            $table->unsignedBigInteger('montant_attendu_centimes')->nullable();
            $table->string('source_montant', 20)->nullable();
            $table->date('echeance')->nullable();

            // Déclaration de l'étudiant : jamais considérée comme une
            // preuve de paiement avant décision administrative.
            $table->unsignedBigInteger('montant_declare_centimes');
            $table->string('reference_paiement', 100);
            $table->date('date_paiement');

            $table->enum('statut', RecuPaiement::STATUTS)
                ->default(RecuPaiement::RECU_DEPOSE);

            $table->text('motif_refus')->nullable();
            $table->text('commentaire')->nullable();

            $table->timestamp('depose_le');

            $table->foreignId('pris_en_charge_par')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('pris_en_charge_le')->nullable();

            $table->foreignId('traite_par')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('traite_le')->nullable();

            // NULL = pas encore validé. true = reçu validé. La contrainte
            // unique (dossier, est_valide) empêche deux validations
            // concurrentes : les NULL multiples restent autorisés.
            $table->boolean('est_valide')->nullable();

            $table->timestamps();

            $table->unique(['dossier_paiement_id', 'version'], 'uniq_recu_dossier_version');
            $table->unique(['dossier_paiement_id', 'est_valide'], 'uniq_recu_valide_par_dossier');
            // Un même fichier ne peut jamais servir deux fois (autre
            // inscription, autre année ou doublon). La contrainte est la
            // garantie de dernier recours, en plus du contrôle applicatif.
            $table->unique('sha256');
            // Un même paiement (référence + date + montant) ne peut être
            // VALIDÉ qu'une seule fois, toutes inscriptions confondues.
            // Les reçus non validés (est_valide = NULL) peuvent coexister :
            // après un refus, l'étudiant peut redéposer un document corrigé
            // portant les mêmes métadonnées. Le contrôle applicatif bloque
            // les doublons entre inscriptions distinctes avant insertion.
            $table->unique(
                ['reference_paiement', 'date_paiement', 'montant_declare_centimes', 'est_valide'],
                'uniq_recu_paiement_valide'
            );
            $table->index(['inscription_academique_id', 'statut']);
            $table->index('statut');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recus_paiement');
        Schema::dropIfExists('dossiers_paiement');
    }
};
