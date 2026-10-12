<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module Schedule : historique immuable des modifications (JSON
 * avant/après, acteur, motif) — sans PII superflue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_slot_id')->constrained('schedule_slots')->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('change_type', [
                'created', 'updated', 'room_changed', 'time_changed',
                'teacher_changed', 'cancelled', 'restored', 'deleted', 'exception',
            ]);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamps();

            $table->index(['schedule_slot_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_changes');
    }
};
