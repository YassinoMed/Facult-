<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE A (Smart Timetables) — disponibilités / indisponibilités déclarées
 * par ressource : un enseignant, une salle ou un groupe peut déclarer des
 * plages récurrentes (jour + heures), dans une portée d'année universitaire
 * optionnelle et une fenêtre de dates effective optionnelle.
 *
 * - `type = indisponible` : contrainte DURE — tout créneau chevauchant la
 *   plage est refusé (ScheduleConflictService::conflitsDisponibilites) ;
 * - `type = souhaitee` : préférence — jamais bloquante, exploitée par le
 *   futur moteur de génération (pondérations Phase B).
 *
 * Exclusivité de ressource (exactement un de teacher_id / room_id /
 * group_id) appliquée applicativement (validation) ; les clés étrangères
 * restent intégrales côté base. Additif strict — aucune donnée modifiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('annee_universitaire_id')->nullable()->constrained('annees_universitaires')->nullOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained('teachers')->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('groups')->cascadeOnDelete();
            $table->enum('day_of_week', [
                'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
            ]);
            $table->time('start_time');
            $table->time('end_time');
            $table->enum('type', ['indisponible', 'souhaitee'])->default('indisponible');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['teacher_id', 'day_of_week'], 'idx_avail_teacher_jour');
            $table->index(['room_id', 'day_of_week'], 'idx_avail_salle_jour');
            $table->index(['group_id', 'day_of_week'], 'idx_avail_groupe_jour');
            $table->index(['type', 'day_of_week'], 'idx_avail_type_jour');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_availabilities');
    }
};
