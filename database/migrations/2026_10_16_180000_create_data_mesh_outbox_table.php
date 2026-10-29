<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table Transactional Outbox pour le Data Mesh logique (§7 du prompt).
 * Permet l'enregistrement transactionnel atomique des événements métier
 * et leur publication garantie après commit, avec déduplication et reprises.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_mesh_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('type_evenement', 100)->index();
            $table->string('type_agregat', 100);
            $table->unsignedBigInteger('agregat_id');
            $table->string('cle_deduplication', 120)->unique();
            $table->json('charge_utile');
            $table->enum('statut', ['pending', 'published', 'failed'])->default('pending')->index();
            $table->unsignedTinyInteger('tentatives')->default(0);
            $table->text('derniere_erreur')->nullable();
            $table->timestamp('publie_le')->nullable();
            $table->timestamps();

            $table->index(['statut', 'created_at'], 'idx_outbox_pending');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_mesh_outbox');
    }
};
