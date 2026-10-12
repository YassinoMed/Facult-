<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module Schedule : exceptions ponctuelles (un créneau, une date).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_slot_id')->constrained('schedule_slots')->cascadeOnDelete();
            $table->date('exception_date');
            $table->time('new_start_time')->nullable();
            $table->time('new_end_time')->nullable();
            $table->foreignId('new_room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('new_teacher_id')->nullable()->constrained('teachers')->nullOnDelete();
            $table->enum('status', ['scheduled', 'cancelled', 'modified', 'moved'])->default('modified');
            $table->string('reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['schedule_slot_id', 'exception_date']);
            $table->index('exception_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_exceptions');
    }
};
