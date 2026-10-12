<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surveillance_assignment_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('session_examen_id')->constrained('evaluation_sessions')->cascadeOnDelete();
            $table->foreignId('faculte_id')->nullable()->constrained('facultes')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('PENDING')->index();
            $table->string('mode', 32)->default('auto');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedInteger('total_epreuves')->default(0);
            $table->unsignedInteger('epreuves_traitees')->default(0);
            $table->unsignedInteger('affectations_creees')->default(0);
            $table->unsignedInteger('affectations_modifiees')->default(0);
            $table->unsignedInteger('conflits')->default(0);
            $table->unsignedInteger('erreurs')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('result_summary')->nullable();
            $table->timestamps();

            $table->index(['session_examen_id', 'status']);
            $table->index(['faculte_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surveillance_assignment_runs');
    }
};
