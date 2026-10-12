<?php

namespace App\Modules\Schedule\Import\Contracts;

use Illuminate\Http\UploadedFile;

/**
 * Contrat d'import (§9) : chaque format (csv/json/xml, xlsx/api demain)
 * convertit vers le FORMAT INTERNE commun — le métier ne connaît
 * jamais le format d'origine.
 *
 * Format interne :
 *
 * @return array{entete: array{academic_year: string, semester: string, group: string, starts_at?: ?string, ends_at?: ?string}, lignes: array<int, array{ligne: int, day: string, start_time: string, end_time: string, course: string, teacher?: ?string, room?: ?string, type?: ?string, notes?: ?string}>}
 */
interface ScheduleImporterInterface
{
    public function format(): string;

    /**
     * @return array{entete: array<string, mixed>, lignes: array<int, array<string, mixed>>}
     */
    public function importer(UploadedFile $fichier): array;
}
