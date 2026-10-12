<?php

namespace App\Services\Documents;

use DomainException;
use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Extraction du texte des modèles téléversés (LOT 3) : HTML/TXT/MD/CSV
 * lus tels quels, DOCX/ODT/RTF convertis en texte brut (mise en forme
 * NON conservée — signalé à l'import). Aucune dépendance ajoutée :
 * ZipArchive + libxml natifs.
 *
 * Sécurité : extensions + MIME réel blanchis, DOCTYPE/ENTITY refusés
 * (anti-XXE, même pattern que `XmlParser`), LIBXML_NONET (jamais
 * NOENT), plafonds anti-zip-bomb, binaires (.doc/.pdf/…) refusés
 * avec message explicite (pas de parseur fiable embarqué).
 */
final class TemplateTextExtractor
{
    /** Extensions acceptées (binaires type .doc/.pdf refusés). */
    public const EXTENSIONS = ['html', 'txt', 'md', 'csv', 'docx', 'odt', 'rtf'];

    /** MIME réels attendus par extension. */
    public const MIMES_PAR_EXTENSION = [
        'html' => ['text/html'],
        'txt' => ['text/plain'],
        'md' => ['text/markdown', 'text/plain', 'text/x-markdown'],
        'csv' => ['text/csv', 'text/plain'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'rtf' => ['application/rtf', 'text/rtf'],
    ];

    private const MAX_TEXTE = 100000;

    private const MAX_DECOMPRESSE = 2000000;

    /**
     * @return array{texte: string, transforme: bool} `transforme` =
     *                                                texte extrait (mise en forme perdue), à signaler à l'import.
     *
     * @throws DomainException
     */
    public function extraire(UploadedFile $fichier): array
    {
        $extension = mb_strtolower((string) $fichier->getClientOriginalExtension());

        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw new DomainException(
                'Format refusé : modèles acceptés — '.implode(', ', array_map(fn ($e) => '.'.$e, self::EXTENSIONS))
                .' (texte extrait, mise en forme non conservée). PDF et Word binaire (.doc) illisibles sans perte.'
            );
        }

        $mime = (string) $fichier->getMimeType();

        if (! in_array($mime, self::MIMES_PAR_EXTENSION[$extension], true)) {
            throw new DomainException("Fichier incohérent : extension .{$extension} avec contenu « {$mime} ».");
        }

        $texte = match ($extension) {
            'docx' => $this->texteDepuisZip($fichier, 'word/document.xml', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w', 'p'),
            'odt' => $this->texteDepuisZip($fichier, 'content.xml', 'urn:oasis:names:tc:opendocument:xmlns:text:1.0', 'text', 'p'),
            'rtf' => $this->texteDepuisRtf($this->lireTexte($fichier)),
            default => $this->lireTexte($fichier),
        };

        $texte = trim($texte);

        if ($texte === '') {
            throw new DomainException('Fichier vide ou illisible : aucun texte extractible.');
        }

        if (mb_strlen($texte) > self::MAX_TEXTE) {
            throw new DomainException('Texte extrait trop volumineux (100 000 caractères maximum).');
        }

        return ['texte' => $texte, 'transforme' => in_array($extension, ['docx', 'odt', 'rtf'], true)];
    }

    private function lireTexte(UploadedFile $fichier): string
    {
        $contenu = $fichier->get();

        if (! is_string($contenu) || ! mb_check_encoding($contenu, 'UTF-8')) {
            throw new DomainException('Fichier illisible : texte UTF-8 attendu.');
        }

        return $contenu;
    }

    /**
     * Texte des paragraphes d'un XML embarqué dans un ZIP (DOCX/ODT).
     *
     * @throws DomainException
     */
    private function texteDepuisZip(UploadedFile $fichier, string $entree, string $espaceNoms, string $prefixe, string $baliseParagraphe): string
    {
        $archive = new ZipArchive;
        $chemin = $fichier->getRealPath();

        if ($chemin === false || $archive->open($chemin) !== true) {
            throw new DomainException('Archive illisible.');
        }

        try {
            $stat = $archive->statName($entree);

            if ($stat === false || (int) ($stat['size'] ?? 0) > self::MAX_DECOMPRESSE) {
                throw new DomainException('Contenu compressé refusé (absent ou trop volumineux).');
            }

            $xml = $archive->getFromName($entree);

            if (! is_string($xml)) {
                throw new DomainException('Contenu documentaire introuvable dans l’archive.');
            }
        } finally {
            $archive->close();
        }

        if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $xml) === 1) {
            throw new DomainException('DOCTYPE et ENTITY interdits (anti-XXE).');
        }

        $document = new \DOMDocument;

        if ($document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT) !== true) {
            throw new DomainException('XML documentaire malformé.');
        }

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace($prefixe, $espaceNoms);

        $paragraphes = [];

        foreach ($xpath->query("//{$prefixe}:{$baliseParagraphe}") as $paragraphe) {
            $texte = trim($paragraphe->textContent);

            if ($texte !== '') {
                $paragraphes[] = $texte;
            }
        }

        return implode("\n\n", $paragraphes);
    }

    /**
     * RTF → texte brut (mots de contrôle supprimés, `\par` conservés).
     * Conversion avec perte assumée et signalée.
     */
    private function texteDepuisRtf(string $rtf): string
    {
        if (! str_starts_with(ltrim($rtf), '{\\rtf')) {
            throw new DomainException('RTF malformé : en-tête {\\rtf attendu.');
        }

        $texte = str_replace(['\\par', '\\line', '\\tab'], ["\n", "\n", "\t"], $rtf);
        $texte = (string) preg_replace("/\\\\'[0-9a-fA-F]{2}/", '', $texte);
        $texte = (string) preg_replace('/\\\\[a-zA-Z]+\d*[ ]?/', '', $texte);
        $texte = str_replace(['{', '}'], '', $texte);

        return (string) preg_replace("/\n{3,}/", "\n\n", $texte);
    }
}
