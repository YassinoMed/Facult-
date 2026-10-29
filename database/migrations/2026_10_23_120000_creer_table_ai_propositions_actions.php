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
        if (! Schema::hasTable('ai_propositions_actions')) {
            Schema::create('ai_propositions_actions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
                $table->foreignId('approbateur_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('faculte_id')->nullable()->constrained('facultes')->nullOnDelete();
                $table->string('type_action', 100);
                $table->string('ressource_type', 100);
                $table->unsignedBigInteger('ressource_id');
                $table->text('description');
                $table->json('parametres');
                $table->text('resume_impact');
                $table->string('niveau_risque', 30)->default('moyen');
                $table->string('statut', 30)->default('pending_approval')->index();
                $table->string('empreinte_sha256', 64)->index();
                $table->timestamp('expire_a')->index();
                $table->timestamp('execute_a')->nullable();
                $table->text('decision_commentaire')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['user_id', 'statut']);
                $table->index(['faculte_id', 'statut']);
                $table->index(['ressource_type', 'ressource_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_propositions_actions');
    }
};
