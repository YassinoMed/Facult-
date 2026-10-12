<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module OnlineRequests : demandes en ligne. Le statut est une machine
 * à états contrôlée serveur (RequestWorkflowService) — jamais fourni
 * par le client. `faculte_id` = snapshot du périmètre à la soumission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('etudiant_id')->constrained('etudiants')->cascadeOnDelete();
            $table->foreignId('request_type_id')->constrained('request_types')->restrictOnDelete();
            $table->foreignId('annee_universitaire_id')->nullable()->constrained('annees_universitaires')->nullOnDelete();
            $table->foreignId('faculte_id')->nullable()->constrained('facultes')->nullOnDelete();
            $table->enum('status', [
                'draft', 'submitted', 'received', 'in_review',
                'waiting_student', 'approved', 'rejected', 'cancelled', 'completed',
            ])->default('draft');
            $table->string('objet', 150);
            $table->string('description', 2000)->nullable();
            $table->json('form_data')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['etudiant_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index(['faculte_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_requests');
    }
};
