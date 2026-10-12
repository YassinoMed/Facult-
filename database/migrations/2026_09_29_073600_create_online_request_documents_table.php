<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module OnlineRequests : documents PDF générés pour les demandes
 * approuvées (attestations…). Registre module-dédié : aucune
 * dépendance vers les tables de StudentDossiers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_request_documents', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('online_request_id')->constrained('online_requests')->cascadeOnDelete();
            $table->string('type', 40);
            $table->unsignedInteger('version')->default(1);
            $table->string('storage_path', 255);
            $table->char('sha256', 64);
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('online_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_request_documents');
    }
};
