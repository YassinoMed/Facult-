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
        if (! Schema::hasTable('time_slots')) {
            Schema::create('time_slots', function (Blueprint $table) {
                $table->id();
                $table->foreignId('faculte_id')->nullable()->constrained('facultes')->nullOnDelete();
                $table->string('code', 30);
                $table->string('nom', 100);
                $table->string('day_of_week', 20)->nullable(); // null = créneau quotidien standard
                $table->time('start_time');
                $table->time('end_time');
                $table->string('type', 30)->default('course'); // course, break, lunch, exam
                $table->boolean('is_mandatory_break')->default(false);
                $table->unsignedSmallInteger('ordre')->default(0);
                $table->boolean('actif')->default(true);
                $table->timestamps();

                $table->index(['faculte_id', 'day_of_week', 'ordre'], 'idx_time_slots_faculte_jour_ordre');
                $table->index(['type', 'is_mandatory_break'], 'idx_time_slots_type_pause');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('time_slots');
    }
};
