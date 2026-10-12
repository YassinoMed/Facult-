<?php

namespace App\Modules\Schedule\Import;

use App\Modules\Schedule\Import\Contracts\ScheduleImporterInterface;
use App\Services\Io\IoException;
use Illuminate\Http\UploadedFile;

/**
 * Import XML (§8/§26) : DOMDocument durci comme le parser Io du cœur —
 * refus de tout DOCTYPE/ENTITY (anti-XXE), LIBXML_NONET (aucune
 * résolution réseau). Projection vers le format interne.
 */
class XmlScheduleImporter implements ScheduleImporterInterface
{
    public function format(): string
    {
        return 'xml';
    }

    public function importer(UploadedFile $fichier): array
    {
        $contenu = $fichier->getContent();

        if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $contenu) === 1) {
            throw IoException::malforme('DOCTYPE et ENTITY sont interdits (anti-XXE).');
        }

        $precedent = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->resolveExternals = false;
        $document->substituteEntities = false;

        try {
            $charge = $document->loadXML($contenu, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($precedent);
        }

        if (! $charge || $document->documentElement === null || $document->documentElement->tagName !== 'schedule') {
            throw IoException::malforme('racine <schedule> attendue.');
        }

        $racine = $document->documentElement;
        $texte = fn (string $tag): string => trim($racine->getElementsByTagName($tag)->item(0)?->textContent ?? '');

        foreach (['academic_year', 'semester', 'group'] as $cle) {
            if ($texte($cle) === '') {
                throw IoException::malforme("balise <{$cle}> absente de l'en-tête XML.");
            }
        }

        $slots = $racine->getElementsByTagName('slot');

        if ($slots->length === 0) {
            throw IoException::malforme('aucun <slot> dans <slots>.');
        }

        $normalisees = [];
        foreach ($slots as $index => $slot) {
            $champ = fn (string $tag): string => trim($slot->getElementsByTagName($tag)->item(0)?->textContent ?? '');
            $normalisees[] = [
                'ligne' => $index + 1,
                'day' => $champ('day'),
                'start_time' => $champ('start_time'),
                'end_time' => $champ('end_time'),
                'course' => $champ('course'),
                'teacher' => $champ('teacher') !== '' ? $champ('teacher') : null,
                'room' => $champ('room') !== '' ? $champ('room') : null,
                'type' => $champ('type') !== '' ? $champ('type') : 'course',
                'notes' => $champ('notes') !== '' ? $champ('notes') : null,
            ];
        }

        $optionnel = fn (string $tag): ?string => $texte($tag) !== '' ? $texte($tag) : null;

        return [
            'entete' => [
                'academic_year' => $texte('academic_year'),
                'semester' => $texte('semester'),
                'group' => $texte('group'),
                'starts_at' => $optionnel('starts_at'),
                'ends_at' => $optionnel('ends_at'),
            ],
            'lignes' => $normalisees,
        ];
    }
}
