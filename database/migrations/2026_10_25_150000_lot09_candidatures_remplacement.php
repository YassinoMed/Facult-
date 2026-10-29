<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| LOT 09 — Candidatures de remplacement pédagogique (audit §F9)
|--------------------------------------------------------------------------
| Les enseignants peuvent se déclarer disponibles pour assurer un
| remplacement de cours dans une matière donnée. L'acceptation par
| l'administration réutilise le remplacement EDT transactionnel
| existant (`ScheduleRepairService::remplacerEnseignant`) — cette
| table ne porte que le volontariat, jamais la mutation de l'EDT.
|
| Migration strictement additive. Rollback : drop de la table — aucune
| donnée EDT perdue (l'historisation des remplacements vit déjà dans
| `schedule_changes`).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remplacement_candidatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->foreignId('matiere_id')->constrained('matieres')->restrictOnDelete();
            $table->text('message')->nullable();
            $table->string('statut', 20)->default('soumise'); // soumise|acceptee|ecartee|retiree
            $table->string('decision_motif', 500)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['teacher_id', 'statut']);
            $table->index(['matiere_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remplacement_candidatures');
    }
};
