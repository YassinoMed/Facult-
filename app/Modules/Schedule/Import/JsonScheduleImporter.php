<?php

namespace App\Modules\Schedule\Import;

use App\Modules\Schedule\Import\Contracts\ScheduleImporterInterface;
use App\Services\Io\IoException;
use Illuminate\Http\UploadedFile;

/**
 * Import JSON (§7) : enveloppe {academic_year, semester, group,
 * starts_at?, ends_at?, slots[]} strictement validée, projection vers
 * le format interne. (Le parser Io du cœur attend une liste de lignes
 * pour d'autres types d'imports — enveloppe dédiée ici, mêmes gardes.)
 */
class JsonScheduleImporter implements ScheduleImporterInterface
{
    public function format(): string
    {
        return 'json';
    }

    public function importer(UploadedFile $fichier): array
    {
        try {
            $donnees = json_decode($fichier->getContent(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw IoException::malforme('JSON invalide.');
        }

        if (! is_array($donnees) || ! isset($donnees['slots']) || ! is_array($donnees['slots'])) {
            throw IoException::malforme('structure JSON attendue : objet avec clés academic_year, semester, group et slots[].');
        }

        foreach (['academic_year', 'semester', 'group'] as $cle) {
            if (($donnees[$cle] ?? '') === '') {
                throw IoException::malforme("clé « {$cle} » absente de l'en-tête JSON.");
            }
        }

        if ($donnees['slots'] === []) {
            throw IoException::malforme('le tableau « slots » est vide.');
        }

        $normalisees = [];
        foreach (array_values($donnees['slots']) as $index => $slot) {
            if (! is_array($slot)) {
                throw IoException::malforme('slot n°'.($index + 1).' : objet attendu.');
            }
            $normalisees[] = [
                'ligne' => $index + 1,
                'day' => (string) ($slot['day'] ?? ''),
                'start_time' => (string) ($slot['start_time'] ?? ''),
                'end_time' => (string) ($slot['end_time'] ?? ''),
                'course' => (string) ($slot['course'] ?? ''),
                'teacher' => ($slot['teacher'] ?? '') !== '' ? (string) $slot['teacher'] : null,
                'room' => ($slot['room'] ?? '') !== '' ? (string) $slot['room'] : null,
                'type' => ($slot['type'] ?? '') !== '' ? (string) $slot['type'] : 'course',
                'notes' => ($slot['notes'] ?? '') !== '' ? (string) $slot['notes'] : null,
            ];
        }

        return [
            'entete' => [
                'academic_year' => (string) $donnees['academic_year'],
                'semester' => (string) $donnees['semester'],
                'group' => (string) $donnees['group'],
                'starts_at' => ($donnees['starts_at'] ?? '') !== '' ? (string) $donnees['starts_at'] : null,
                'ends_at' => ($donnees['ends_at'] ?? '') !== '' ? (string) $donnees['ends_at'] : null,
            ],
            'lignes' => $normalisees,
        ];
    }
}
