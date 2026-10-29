<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('schedule_slot_teachers')) {
            Schema::create('schedule_slot_teachers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('schedule_slot_id')->constrained('schedule_slots')->cascadeOnDelete();
                $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
                $table->string('role', 30)->default('principal'); // principal, coenseignant, assistant
                $table->decimal('volume_heures', 4, 2)->nullable();
                $table->timestamps();

                $table->unique(['schedule_slot_id', 'teacher_id'], 'uniq_slot_teacher');
                $table->index(['teacher_id', 'role'], 'idx_slot_teachers_role');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_slot_teachers');
    }
};
