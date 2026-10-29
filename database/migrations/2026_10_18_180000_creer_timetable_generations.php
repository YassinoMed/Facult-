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
        if (! Schema::hasTable('timetable_generations')) {
            Schema::create('timetable_generations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('schedule_id')->nullable()->constrained('schedules')->cascadeOnDelete();
                $table->foreignId('faculte_id')->nullable()->constrained('facultes')->nullOnDelete();
                $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('statut', 30)->default('en_cours'); // en_cours, succes, partiel, echec, annule
                $table->unsignedSmallInteger('nombre_seances_a_placer')->default(0);
                $table->unsignedSmallInteger('nombre_seances_placees')->default(0);
                $table->unsignedInteger('nombre_iterations')->default(0);
                $table->decimal('duree_ms', 10, 2)->default(0);
                $table->unsignedSmallInteger('score_qualite')->nullable();
                $table->json('parametres')->nullable();
                $table->json('statistiques')->nullable();
                $table->json('erreurs')->nullable();
                $table->json('instantané_avant')->nullable();
                $table->timestamps();

                $table->index(['schedule_id', 'statut'], 'idx_tt_gen_schedule_statut');
                $table->index(['faculte_id', 'created_at'], 'idx_tt_gen_faculte_date');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('timetable_generations');
    }
};
