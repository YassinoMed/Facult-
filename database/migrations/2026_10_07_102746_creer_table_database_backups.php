<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOOP BACKUP-01 — registre des sauvegardes : métadonnées seules,
 * jamais de contenu SQL ni de secrets. Les fichiers vivent hors
 * webroot (dossier configuré, jamais servis directement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_backups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('filename', 255);
            $table->string('disk', 30)->default('local');
            $table->string('path', 500);
            $table->string('database_name', 100)->nullable();
            $table->string('connexion', 50)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum', 64)->nullable();
            $table->string('checksum_algorithm', 10)->default('sha256');
            $table->string('compression', 10)->nullable();
            $table->enum('status', ['RUNNING', 'SUCCESS', 'FAILED', 'VERIFICATION_FAILED', 'RESTORE_TEST_FAILED', 'DELETED'])->default('RUNNING');
            $table->enum('type', ['MANUAL', 'SCHEDULED', 'PRE_DEPLOYMENT', 'PRE_MIGRATION'])->default('MANUAL');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('restore_tested_at')->nullable();
            $table->string('restore_test_status', 20)->nullable();
            $table->string('error_code', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_backups');
    }
};
