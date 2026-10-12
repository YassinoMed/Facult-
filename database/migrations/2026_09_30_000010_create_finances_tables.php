<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 23 — Finances universitaires : tarifs configurables, factures
 * (jamais supprimées : cancelled), paiements confirmés (montants en
 * centimes INTEGER, jamais de floats), remboursements contrôlés.
 * Aucune donnée bancaire sensible stockée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tarifs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30);
            $table->string('libelle', 150);
            $table->unsignedBigInteger('montant_centimes');
            $table->foreignId('annee_universitaire_id')->constrained('annees_universitaires')->restrictOnDelete();
            $table->foreignId('programme_id')->nullable()->constrained('programmes')->restrictOnDelete();
            $table->string('niveau', 10)->nullable();
            $table->string('type_frais', 30)->default('inscription');
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->unique(['code', 'annee_universitaire_id']);
            $table->index(['annee_universitaire_id', 'actif']);
        });

        Schema::create('factures', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('etudiant_id')->constrained('etudiants')->restrictOnDelete();
            $table->foreignId('annee_universitaire_id')->constrained('annees_universitaires')->restrictOnDelete();
            $table->foreignId('inscription_academique_id')->nullable()->constrained('inscriptions_academiques')->restrictOnDelete();
            $table->string('statut', 20)->default('draft');
            $table->unsignedBigInteger('total_centimes')->default(0);
            $table->unsignedBigInteger('remise_centimes')->default(0);
            $table->string('motif_remise', 255)->nullable();
            $table->date('date_echeance')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('annulee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motif_annulation')->nullable();
            $table->timestamps();
            $table->index(['etudiant_id', 'statut']);
            $table->index(['annee_universitaire_id', 'statut']);
        });

        Schema::create('facture_lignes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facture_id')->constrained('factures')->cascadeOnDelete();
            $table->foreignId('tarif_id')->nullable()->constrained('tarifs')->restrictOnDelete();
            $table->string('libelle', 150);
            $table->unsignedBigInteger('montant_centimes');
            $table->timestamps();
        });

        Schema::create('paiements', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('facture_id')->constrained('factures')->restrictOnDelete();
            $table->unsignedBigInteger('montant_centimes');
            $table->string('methode', 20)->default('especes');
            $table->string('statut', 20)->default('pending');
            $table->string('reference_externe', 100)->nullable()->unique();
            $table->foreignId('enregistre_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirme_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirme_a')->nullable();
            $table->timestamps();
            $table->index(['facture_id', 'statut']);
        });

        Schema::create('remboursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paiement_id')->constrained('paiements')->restrictOnDelete();
            $table->unsignedBigInteger('montant_centimes');
            $table->string('statut', 20)->default('requested');
            $table->text('motif');
            $table->foreignId('demande_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('traite_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('traite_a')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remboursements');
        Schema::dropIfExists('paiements');
        Schema::dropIfExists('facture_lignes');
        Schema::dropIfExists('factures');
        Schema::dropIfExists('tarifs');
    }
};
