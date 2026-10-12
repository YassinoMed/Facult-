<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module StudentDossiers — PDF (§8) : registre des documents générés.
 * Migration indépendante : aucune table du cœur modifiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_documents', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('student_dossier_id')->constrained('student_dossiers')->cascadeOnDelete();
            $table->string('type', 30)->default('dossier');
            $table->unsignedInteger('version')->default(1);
            $table->string('storage_path', 255);
            $table->char('sha256', 64);
            $table->char('contenu_hash', 64);
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['student_dossier_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_documents');
    }
};
