<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module OnlineRequests : pièces justificatives (disque privé,
 * métadonnées + SHA-256 — même discipline que StudentDossiers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('online_request_id')->constrained('online_requests')->cascadeOnDelete();
            $table->string('original_filename', 255);
            $table->string('storage_path', 255);
            $table->string('mime_type', 100);
            $table->string('extension', 10);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('online_request_id');
            $table->index('sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_attachments');
    }
};
