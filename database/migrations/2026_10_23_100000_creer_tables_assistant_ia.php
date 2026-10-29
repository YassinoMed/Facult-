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
        if (! Schema::hasTable('ai_conversations')) {
            Schema::create('ai_conversations', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
                $table->foreignId('faculte_id')->nullable()->constrained('facultes')->nullOnDelete();
                $table->string('titre', 255)->default('Nouvelle conversation');
                $table->string('mode', 50)->default('metier');
                $table->string('fournisseur', 50)->nullable();
                $table->string('modele', 100)->nullable();
                $table->boolean('epingle')->default(false);
                $table->timestamp('dernier_message_a')->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['user_id', 'created_at']);
                $table->index(['user_id', 'mode']);
            });
        }

        if (! Schema::hasTable('ai_messages')) {
            Schema::create('ai_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
                $table->string('role', 20)->default('user');
                $table->text('contenu');
                $table->json('appels_outils')->nullable();
                $table->json('resultats_outils')->nullable();
                $table->json('sources')->nullable();
                $table->unsignedInteger('tokens_prompt')->default(0);
                $table->unsignedInteger('tokens_reponse')->default(0);
                $table->float('latence_ms')->default(0.0);
                $table->timestamps();

                $table->index(['conversation_id', 'id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
