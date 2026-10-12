<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module Schedule (§20-21) : historique des imports (qui, quoi, quand,
 * combien). Le fichier source n'est jamais persisté — seules les
 * erreurs structurées (sans PII superflue) sont conservées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->nullable()->constrained('schedules')->nullOnDelete();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('filename', 255);
            $table->string('format', 10);
            $table->enum('status', ['pending', 'validated', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('successful_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->unsignedInteger('warnings_count')->default(0);
            $table->json('errors')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_imports');
    }
};
