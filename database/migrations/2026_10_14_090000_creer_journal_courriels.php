<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Journal technique des courriels (LOOP-MAIL-ENTERPRISE-01, LOOP 04) :
     * supervision et audit des envois, JAMAIS de contenu sensible.
     * Interdits ici : corps du message, code OTP, jeton, lien signé,
     * mot de passe, adresse en clair (seule une forme masquée + un
     * condensat SHA-256 pour la déduplication sont conservés).
     */
    public function up(): void
    {
        Schema::create('courriels', function (Blueprint $table) {
            $table->id();
            $table->string('cle_idempotence', 64)->nullable()->unique();
            $table->string('categorie', 40);
            $table->string('modele', 80);
            $table->string('version_modele', 20)->default('1');
            $table->string('statut', 20)->default('pending');
            $table->unsignedSmallInteger('tentatives')->default(0);
            $table->string('categorie_erreur', 40)->nullable();
            $table->string('destinataire_masque', 120);
            $table->char('destinataire_hash', 64);
            $table->string('sujet', 255);
            $table->string('correlation_id', 64)->nullable();
            $table->string('reference_metier', 80)->nullable();
            $table->unsignedBigInteger('acteur_admin_id')->nullable();
            $table->timestamp('envoye_le')->nullable();
            $table->json('donnees')->nullable();
            $table->timestamps();
            $table->index(['statut', 'created_at']);
            $table->index(['modele', 'created_at']);
            $table->index('destinataire_hash');
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courriels');
    }
};
