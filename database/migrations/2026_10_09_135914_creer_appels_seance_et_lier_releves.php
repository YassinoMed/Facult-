<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Appel intelligent (espace enseignant) :
 * - `seance_appels` : état d'un appel (séance × date) — en_cours/clôturé —
 *   avec snapshot des compteurs à la clôture. L'absence de ligne =
 *   appel non commencé (jamais confondu avec un appel vide) ;
 * - `presences` : rattachement `schedule_slot_id` (séance officielle,
 *   contrainte souple nullOnDelete) + auteur enseignant `teacher_id`
 *   (`enregistre_par` reste l'auteur User back-office) + `historique`
 *   JSON des corrections (miroir `teacher_presences.historique`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seance_appels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_slot_id')->constrained('schedule_slots')->cascadeOnDelete();
            $table->date('date');
            $table->enum('statut', ['en_cours', 'cloture'])->default('en_cours');
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->unsignedInteger('presents')->default(0);
            $table->unsignedInteger('absents')->default(0);
            $table->unsignedInteger('retards')->default(0);
            $table->unsignedInteger('non_renseignes')->default(0);
            $table->timestamp('cloture_le')->nullable();
            $table->timestamps();
            $table->unique(['schedule_slot_id', 'date']);
            $table->index(['teacher_id', 'date']);
        });

        Schema::table('presences', function (Blueprint $table) {
            $table->foreignId('schedule_slot_id')->nullable()->after('etudiant_id')->constrained('schedule_slots')->nullOnDelete();
            $table->foreignId('teacher_id')->nullable()->after('schedule_slot_id')->constrained('teachers')->nullOnDelete();
            $table->json('historique')->nullable()->after('motif');
            $table->index(['schedule_slot_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('presences', function (Blueprint $table) {
            $table->dropForeign(['schedule_slot_id']);
            $table->dropForeign(['teacher_id']);
            $table->dropColumn(['schedule_slot_id', 'teacher_id', 'historique']);
        });
        Schema::dropIfExists('seance_appels');
    }
};
