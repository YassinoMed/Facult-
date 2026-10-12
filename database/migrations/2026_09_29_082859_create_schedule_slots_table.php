<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module Schedule : créneaux hebdomadaires récurrents. Matière =
 * modèle `Matiere` existant réutilisé (aucune table course créée).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('schedules')->cascadeOnDelete();
            $table->foreignId('matiere_id')->constrained('matieres')->restrictOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('teachers')->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->enum('day_of_week', [
                'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
            ]);
            $table->time('start_time');
            $table->time('end_time');
            $table->enum('type', ['course', 'td', 'tp', 'exam', 'meeting', 'workshop', 'other'])->default('course');
            $table->enum('status', ['scheduled', 'cancelled', 'modified', 'completed'])->default('scheduled');
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['schedule_id', 'day_of_week']);
            $table->index(['teacher_id', 'day_of_week']);
            $table->index(['room_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_slots');
    }
};
