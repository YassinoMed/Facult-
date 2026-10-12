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
        // Phase 15 §15.1 : préférences de notification par destinataire
        // (opt-out par catégorie, jamais pour les alertes obligatoires).
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('etudiant_id')->nullable()->constrained('etudiants')->cascadeOnDelete();
            $table->string('categorie', 40);
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'etudiant_id', 'categorie'], 'uniq_notif_pref');
            $table->index(['etudiant_id', 'categorie']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
