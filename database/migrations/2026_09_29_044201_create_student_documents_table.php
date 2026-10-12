<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module StudentDossiers (§2) : documents confidentiels. Seules des
 * métadonnées sont stockées — le contenu vit sur le disque privé.
 * `storage_path` n'est JAMAIS exposé (modèle hidden + Resource).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_dossier_id')->constrained('student_dossiers')->cascadeOnDelete();
            $table->string('category', 30);
            $table->string('document_type', 60);
            $table->string('title', 150);
            $table->string('description', 500)->nullable();
            $table->string('original_filename', 255);
            $table->string('storage_path', 255);
            $table->string('mime_type', 100);
            $table->string('extension', 10);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->enum('status', ['pending', 'validated', 'rejected', 'expired', 'archived'])->default('pending');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->string('rejected_reason', 500)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['student_dossier_id', 'status']);
            $table->index(['student_dossier_id', 'category']);
            $table->index('status');
            $table->index('sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_documents');
    }
};
