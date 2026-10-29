<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOT 03 — Registre persistant des définitions KPI de scolarité.
 *
 * Branche la couche de définitions KPI (déjà documentées EN CODE par
 * `BIService` et le catalogue du cockpit `App\Services\Kpi\CatalogueKpi`)
 * sur un registre en base : chaque définition est versionnée (bump de
 * version dès que la définition ou la source change — dérive
 * documentaire détectée) et porte le dernier snapshot de valeur calculé
 * par le périmètre BI.
 *
 * Migration additive pure : aucune table/colonne existante modifiée.
 * Rejouable (garde Schema::hasTable). Rollback : `Schema::drop` — le
 * registre reste un cache de documentation ; la couche KPI reste
 * fonctionnelle sans la table (garde fail-soft dans `BIService`
 * et `KpiDefinition::tableDisponible()`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kpi_definitions')) {
            return;
        }

        Schema::create('kpi_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('cle');
            $table->string('provenance')->default('bi_service')->index();
            $table->string('nom')->nullable();
            $table->text('definition');
            $table->string('source')->nullable();
            $table->string('unite')->nullable();
            $table->string('categorie')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('definition_hash', 32)->index();
            $table->boolean('actif')->default(true)->index();
            $table->json('dernier_snapshot')->nullable();
            $table->timestamp('dernier_snapshot_a')->nullable();
            $table->timestamps();

            $table->unique(['cle', 'provenance']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_definitions');
    }
};
