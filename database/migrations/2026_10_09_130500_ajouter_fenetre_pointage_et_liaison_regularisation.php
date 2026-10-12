<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Présence intelligente enseignants :
 * - paramètre `presences.fenetre_pointage_minutes` (défaut 60, borne
 *   0..180) : demi-fenêtre d'appariement pointages↔séances, jusqu'ici en
 *   dur dans `PresenceEmploiDuTempsService` ;
 * - liaison `teacher_reclamations.teacher_presence_id` (nullable,
 *   nullOnDelete) : une demande de régularisation porte sur UNE présence
 *   contestée (traçabilité demande→décision), sans dupliquer le planning.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('parametres')->updateOrInsert(
            ['cle' => 'presences.fenetre_pointage_minutes'],
            [
                'valeur' => '60', 'type' => 'entier',
                'categorie' => 'presences',
                'description' => "Demi-fenêtre d'appariement (minutes) autour d'une séance pour rattacher les pointages bruts (rapprochement enseignants/emploi du temps).",
                'created_at' => now(), 'updated_at' => now(),
            ]
        );

        if (Schema::hasTable('teacher_reclamations') && ! Schema::hasColumn('teacher_reclamations', 'teacher_presence_id')) {
            Schema::table('teacher_reclamations', function (Blueprint $table) {
                $table->foreignId('teacher_presence_id')->nullable()->after('teacher_id')->constrained('teacher_presences')->nullOnDelete();
                $table->index(['teacher_presence_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('teacher_reclamations', function (Blueprint $table) {
            $table->dropForeign(['teacher_presence_id']);
            $table->dropColumn('teacher_presence_id');
        });
        DB::table('parametres')->where('cle', 'presences.fenetre_pointage_minutes')->delete();
    }
};
