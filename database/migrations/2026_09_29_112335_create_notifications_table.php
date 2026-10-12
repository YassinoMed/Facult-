<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Centre de notifications (§4) : destinataire user OU étudiant (les
 * deux authentifications coexistent), contenu JSON, lecture horodatée.
 * Compatible dans l'esprit avec les notifications Laravel (type,
 * data) sans exiger leur infrastructure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('etudiant_id')->nullable()->constrained('etudiants')->cascadeOnDelete();
            $table->string('type', 60);
            $table->string('titre', 150);
            $table->string('message', 500);
            $table->json('data')->nullable();
            $table->string('lien', 255)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['etudiant_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
