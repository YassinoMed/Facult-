<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration d'extension du module Attendance : suivi intelligent des présences
 * des enseignants fondé sur les séances de l'emploi du temps (ScheduleSlot)
 * et le rapprochement déterministe des pointages réels.
 *
 * Tables additives :
 * 1. `teacher_pointages` : pointages bruts (badgeuse, terminal, portail, manuel, QR).
 * 2. `teacher_presences` : rapprochement séance x date (statut calculé vs statut validé humain).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_pointages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('teachers')->restrictOnDelete();
            $table->date('date');
            $table->dateTime('horodatage');
            $table->enum('type', ['entree', 'sortie', 'presence'])->default('entree');
            $table->enum('source', ['badgeuse', 'manuel', 'terminal', 'portail', 'qr', 'autre'])->default('manuel');
            $table->string('terminal_ip', 45)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('enregistre_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['teacher_id', 'date']);
            $table->index('date');
            $table->index('horodatage');
        });

        Schema::create('teacher_presences', function (Blueprint $table) {
            $table->id();
            // RESTRICT (jamais cascade) : la suppression d'un créneau ou d'un
            // enseignant ne doit JAMAIS détruire l'historique des présences
            // déjà validées (§8) — le workflow de retrait d'une séance est
            // l'annulation (statut), pas la suppression.
            $table->foreignId('schedule_slot_id')->constrained('schedule_slots')->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained('teachers')->restrictOnDelete();
            $table->date('date');
            $table->time('heure_arrivee')->nullable();
            $table->time('heure_depart')->nullable();
            $table->enum('statut_calcule', [
                'a_venir',
                'en_cours',
                'presence_confirmee',
                'retard_potentiel',
                'pointage_manquant',
                'absence_a_verifier',
                'seance_annulee',
                'dispense_mission',
            ])->default('a_venir');
            $table->enum('statut_valide', [
                'presence_confirmee',
                'absence_validee',
                'retard_justifie',
                'dispense_mission',
                'absence_rejetee',
            ])->nullable();
            $table->integer('retard_minutes')->default(0);
            $table->string('motif', 500)->nullable();
            $table->foreignId('valide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('valide_le')->nullable();
            $table->json('historique')->nullable();
            $table->timestamps();

            // Unicité : une seule observation consolidée par créneau et par date calendaire
            $table->unique(['schedule_slot_id', 'date']);
            $table->index(['teacher_id', 'date']);
            $table->index('date');
            $table->index('statut_calcule');
            $table->index('statut_valide');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_presences');
        Schema::dropIfExists('teacher_pointages');
    }
};
