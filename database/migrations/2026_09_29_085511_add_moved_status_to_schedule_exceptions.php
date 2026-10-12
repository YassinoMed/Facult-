<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Module Schedule (§10) : statut `moved` (déplacé) pour les exceptions.
 * MySQL uniquement : SQLite ne supporte pas la modification d'enum
 * (le validateur accepte déjà `moved` partout — cf. modèle).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE schedule_exceptions MODIFY COLUMN status ENUM('scheduled', 'cancelled', 'modified', 'moved') NOT NULL DEFAULT 'modified'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("UPDATE schedule_exceptions SET status = 'modified' WHERE status = 'moved'");
        DB::statement("ALTER TABLE schedule_exceptions MODIFY COLUMN status ENUM('scheduled', 'cancelled', 'modified') NOT NULL DEFAULT 'modified'");
    }
};
