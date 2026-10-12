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
        // Phase 9 §61 : fil de discussion demande ↔ administration.
        // Auteur = user back-office OU étudiant (jamais les deux,
        // jamais aucun — contrainte applicative, pas CHECK portable).
        Schema::create('request_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('online_request_id')->constrained('online_requests')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('etudiant_id')->nullable()->constrained('etudiants')->nullOnDelete();
            $table->text('message');
            $table->timestamps();

            $table->index(['online_request_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('request_messages');
    }
};
