<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MASTER LOOP consolidation §2 — garde durable « une seule maquette
 * active par programme » AU NIVEAU BASE : l'unicité applicative repose
 * sur `MaquetteService::transiter` (lockForUpdate éprouvé MySQL), mais
 * rien n'empêchait un écrit direct (import, script, futur chemin non
 * verrouillé) de créer deux `active` — prouvé sur base scratch MySQL
 * avant cette migration.
 *
 * Mécanisme (MySQL, testé compatible en scratch avant application) :
 * colonne générée `active_programme_id` = programme_id si statut
 * 'active' sinon NULL (les NULL multiples ne violent pas l'index
 * unique) + index unique dessus. La bascule atomique existante
 * (archiver les autres PUIS activer la cible, dans cet ordre) reste
 * valide : la ligne archivée quitte l'index avant l'entrée de la
 * nouvelle.
 *
 * Idempotente (n'exécute que si absent), réversible, no-op hors MySQL
 * (sqlite de test : la garde applicative suffit, pattern de la
 * migration des CHECK notes).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (! $this->colonneExiste()) {
            DB::statement("ALTER TABLE maquettes ADD COLUMN active_programme_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN statut = 'active' THEN programme_id ELSE NULL END) STORED");
        }

        if (! $this->indexExiste()) {
            DB::statement('ALTER TABLE maquettes ADD UNIQUE maquettes_active_programme_unique (active_programme_id)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if ($this->indexExiste()) {
            DB::statement('ALTER TABLE maquettes DROP INDEX maquettes_active_programme_unique');
        }

        if ($this->colonneExiste()) {
            DB::statement('ALTER TABLE maquettes DROP COLUMN active_programme_id');
        }
    }

    private function colonneExiste(): bool
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['maquettes', 'active_programme_id']
        )->n > 0;
    }

    private function indexExiste(): bool
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) AS n FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            ['maquettes', 'maquettes_active_programme_unique']
        )->n > 0;
    }
};
