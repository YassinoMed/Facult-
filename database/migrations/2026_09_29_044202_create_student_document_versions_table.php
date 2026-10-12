<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module StudentDossiers (§3) : versionnage — un remplacement crée une
 * nouvelle version, l'historique (fichiers + métadonnées) est conservé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_document_id')->constrained('student_documents')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('original_filename', 255);
            $table->string('storage_path', 255);
            $table->string('mime_type', 100);
            $table->string('extension', 10);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['student_document_id', 'version']);
            $table->index('sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_document_versions');
    }
};
