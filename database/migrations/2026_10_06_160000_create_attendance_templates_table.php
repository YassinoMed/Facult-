<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module Attendance (§10-12) : table des modèles de fiches de présence.
 * Permet de définir des gabarits système et personnalisés avec configuration JSON
 * sans modifier le moteur métier central de présence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('code', 60)->unique();
            $table->text('description')->nullable();
            $table->string('type', 40); // classic, emargement, detailed, td, tp, exam, double_signature, qr, custom
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('configuration');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'is_active']);
            $table->index(['is_system', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_templates');
    }
};
