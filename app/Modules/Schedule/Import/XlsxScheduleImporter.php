<?php

namespace App\Modules\Schedule\Import;

use App\Modules\Schedule\Import\Contracts\ScheduleImporterInterface;
use App\Services\Io\IoException;
use App\Services\Io\IoFactory;
use Illuminate\Http\UploadedFile;

/**
 * Import XLSX : lignes du parser Io partagé (limite anti-bombe,
 * première feuille) projetées vers le format interne — mêmes règles
 * métier que CSV/JSON/XML, zéro duplication.
 */
class XlsxScheduleImporter implements ScheduleImporterInterface
{
    public function format(): string
    {
        return 'xlsx';
    }

    public function importer(UploadedFile $fichier): array
    {
        $lignes = IoFactory::parserPour('xlsx')->parse($fichier);

        $requises = ['academic_year', 'semester', 'group', 'day', 'start_time', 'end_time', 'course'];
        $entetes = array_keys($lignes[0]);
        $manquantes = array_diff($requises, $entetes);

        if ($manquantes !== []) {
            throw IoException::malforme('colonnes manquantes : '.implode(', ', $manquantes).'.');
        }

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
