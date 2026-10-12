<?php

namespace App\Modules\Schedule\Import;

use App\Modules\Schedule\Import\Contracts\ScheduleImporterInterface;
use App\Services\Io\IoException;
use App\Services\Io\IoFactory;
use Illuminate\Http\UploadedFile;

/**
 * Import CSV (§6) : parse via le parser Io partagé (séparateur auto,
 * BOM, accents UTF-8), puis projection vers le format interne.
 * Colonnes : academic_year,semester,group,day,start_time,end_time,
 * course,teacher,room,type (+ starts_at/ends_at optionnels).
 */
class CsvScheduleImporter implements ScheduleImporterInterface
{
    public function format(): string
    {
        return 'csv';
    }

    public function importer(UploadedFile $fichier): array
    {
        $lignes = IoFactory::parserPour('csv')->parse($fichier);

        $requises = ['academic_year', 'semester', 'group', 'day', 'start_time', 'end_time', 'course'];
        $entetes = $lignes !== [] ? array_keys($lignes[0]) : [];
        $manquantes = array_diff($requises, $entetes);

        if ($manquantes !== []) {
            throw IoException::malforme('colonnes manquantes : '.implode(', ', $manquantes).'.');
        }

        // En-tête porté par la première ligne de données (§6).
        $premiere = $lignes[0];

        $normalisees = [];
        foreach ($lignes as $index => $ligne) {
            $normalisees[] = [
                'ligne' => $index + 2,
                'day' => (string) ($ligne['day'] ?? ''),
                'start_time' => (string) ($ligne['start_time'] ?? ''),
                'end_time' => (string) ($ligne['end_time'] ?? ''),
                'course' => (string) ($ligne['course'] ?? ''),
                'teacher' => ($ligne['teacher'] ?? '') !== '' ? (string) $ligne['teacher'] : null,
                'room' => ($ligne['room'] ?? '') !== '' ? (string) $ligne['room'] : null,
                'type' => ($ligne['type'] ?? '') !== '' ? (string) $ligne['type'] : 'course',
                'notes' => ($ligne['notes'] ?? '') !== '' ? (string) $ligne['notes'] : null,
            ];
        }

        return [
            'entete' => [
                'academic_year' => (string) ($premiere['academic_year'] ?? ''),
                'semester' => (string) ($premiere['semester'] ?? ''),
                'group' => (string) ($premiere['group'] ?? ''),
                'starts_at' => ($premiere['starts_at'] ?? '') !== '' ? (string) $premiere['starts_at'] : null,
                'ends_at' => ($premiere['ends_at'] ?? '') !== '' ? (string) $premiere['ends_at'] : null,
            ],
            'lignes' => $normalisees,
        ];
    }
}
