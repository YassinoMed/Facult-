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
        // Phase 7 §7.4 : favoris épinglés (entité ou page), par
        // utilisateur. Le lien pointe vers une page déjà gated —
        // l'autorisation reste vérifiée à l'ouverture, jamais ici.
        Schema::create('user_favoris', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('reference', 120);
            $table->string('titre', 150);
            $table->string('lien', 255);
            $table->timestamps();

            $table->unique(['user_id', 'type', 'reference']);
            $table->index(['user_id', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_favoris');
    }
};
