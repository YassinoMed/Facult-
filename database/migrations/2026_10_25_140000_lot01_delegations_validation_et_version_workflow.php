<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| LOT 01 — Workflow de validation configurable (audit §F1)
|--------------------------------------------------------------------------
| 1. `request_delegations` : délégations de l'autorité de validation
|    (approbation/rejet des demandes) — fenêtre bornée, révocable,
|    le délégant devant détenir l'autorité déléguée.
| 2. `online_requests.version_workflow` : version de la définition de
|    workflow épinglée à la création — une demande créée sous la
|    version N conserve les règles de la version N (conservation du
|    moteur, LOT 01).
|
| Migration strictement additive. Rollback documenté : suppression de
| la table `request_delegations` et de la colonne `version_workflow`
| — aucune donnée métier perdue (version 1 = socle actuel, valeur par
| défaut du backfill).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delegue_par')->constrained('users')->cascadeOnDelete();
            $table->foreignId('delegue_a')->constrained('users')->cascadeOnDelete();
            $table->foreignId('faculte_id')->nullable()->constrained('facultes')->nullOnDelete();
            $table->timestamp('valide_depuis');
            $table->timestamp('valide_jusqua');
            $table->string('motif', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['delegue_a', 'valide_jusqua']);
            $table->index(['delegue_par', 'valide_jusqua']);
        });

        Schema::table('online_requests', function (Blueprint $table) {
            $table->unsignedInteger('version_workflow')->default(1)->after('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_delegations');

        Schema::table('online_requests', function (Blueprint $table) {
            $table->dropColumn('version_workflow');
        });
    }
};
