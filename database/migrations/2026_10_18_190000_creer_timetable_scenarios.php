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
        if (! Schema::hasTable('timetable_scenarios')) {
            Schema::create('timetable_scenarios', function (Blueprint $table) {
                $table->id();
                $table->foreignId('schedule_id')->constrained('schedules')->cascadeOnDelete();
                $table->string('code', 50);
                $table->string('nom', 120);
                $table->string('statut', 30)->default('brouillon'); // brouillon, evalue, retenu, rejete
                $table->unsignedSmallInteger('score_qualite')->nullable();
                $table->unsignedSmallInteger('nb_conflits_durs')->default(0);
                $table->json('creneaux_json'); // instantané complet des créneaux composant ce scénario
                $table->json('metriques_json')->nullable();
                $table->json('parametres_optimisation')->nullable();
                $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['schedule_id', 'statut'], 'idx_tt_scenarios_sched_statut');
                $table->unique(['schedule_id', 'code'], 'uniq_tt_scenario_schedule_code');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('timetable_scenarios');
    }
};
