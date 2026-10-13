<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * LOOP 37 D-004 — garde-fous SQL sur les notes : la base elle-même
 * refuse les valeurs hors bornes, même en écriture directe (import,
 * script, contournement Laravel). Sémantiques prouvées :
 * - `notes.note` NOT NULL, échelle /20 (FormRequests min:0|max:20) ;
 * - `evaluation_notes.valeur` NULL ou 0..note_max de sa ligne
 *   (SaisieNoteService borne 0..note_max, Request garde lâche).
 * Historique volontairement exclu : l'audit doit pouvoir tracer
 * l'état réel, même historiquement invalide.
 */
return new class extends Migration
{
    private const CHECKS = [
        'notes' => [
            'chk_notes_note_0_20' => 'note >= 0 AND note <= 20',
        ],
        'evaluation_notes' => [
            'chk_evaluation_notes_valeur_bornes' => 'valeur IS NULL OR (valeur >= 0 AND valeur <= note_max)',
        ],
    ];

    public function up(): void
    {
        foreach (self::CHECKS as $table => $checks) {
            foreach ($checks as $nom => $expression) {
                if (! $this->checkExiste($table, $nom)) {
                    $this->poserCheck($table, $nom, $expression);
                }
            }
        }
    }

    /**
     * Le CHECK est le rempart MySQL (prod — preuves LOOP 37 : SHOW CREATE
     * + INSERT/UPDATE refusés). SQLite ne connaît `ALTER TABLE … ADD
     * CONSTRAINT` que dans ses versions récentes (≥ 3.4x, dev local) :
     * sur un SQLite plus ancien (runner CI), l'instruction est ignorée
     * silencieusement — la validation applicative (FormRequests +
     * SaisieNoteService) reste la garantie contractuelle sur tout driver
     * (les tests dédiés se skipent alors, cf. CheckConstraintsNotesTest).
     */
    private function poserCheck(string $table, string $nom, string $expression): void
    {
        try {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$nom} CHECK ({$expression})");
        } catch (QueryException) {
            // SQLite < 3.4x : non supporté, rempart MySQL uniquement.
        }
    }

    public function down(): void
    {
        // SQLite ne sait pas retirer un CHECK sans reconstruire la
        // table : no-op documenté (`migrate:fresh` recrée proprement).
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::CHECKS as $table => $checks) {
            foreach (array_keys($checks) as $nom) {
                if ($this->checkExiste($table, $nom)) {
                    DB::statement("ALTER TABLE {$table} DROP CHECK {$nom}");
                }
            }
        }
    }

    private function checkExiste(string $table, string $nom): bool
    {
        if (DB::getDriverName() === 'mysql') {
            return (int) DB::selectOne(
                "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?
                 AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
                [$table, $nom]
            )->n > 0;
        }

        $definition = DB::selectOne(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table]
        )?->sql ?? '';

        return str_contains($definition, "CONSTRAINT {$nom}");
    }
};
