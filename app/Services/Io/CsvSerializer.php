<?php

namespace App\Services\Io;

/**
 * Sérialiseur CSV avec protection contre la formula/CSV injection (§62) :
 * toute valeur commençant par = + - @ est préfixée d'une apostrophe afin
 * qu'aucune donnée ne devienne une formule Excel à l'ouverture.
 */
class CsvSerializer implements SerializesExports
{
    public function format(): string
    {
        return 'csv';
    }

    public function mimeType(): string
    {
        return 'text/csv; charset=UTF-8';
    }

    public function serialize(array $entetes, array $lignes): string
    {
        $sortie = fopen('php://temp', 'r+');

        fwrite($sortie, "\xEF\xBB\xBF"); // BOM UTF-8 pour Excel
        fputcsv($sortie, $entetes, ';');

        foreach ($lignes as $ligne) {
            fputcsv($sortie, array_map(
                fn ($valeur) => $this->neutraliserFormule((string) ($valeur ?? '')),
                array_map(fn ($entete) => $ligne[$entete] ?? '', $entetes),
            ), ';');
        }

        rewind($sortie);
        $contenu = (string) stream_get_contents($sortie);
        fclose($sortie);

        return $contenu;
    }

    /**
     * Neutralise la CSV/formula injection : =CMD, +CMD, -CMD, @CMD, \tCMD, \rCMD
     */
    public function neutraliserFormule(string $valeur): string
    {
        if ($valeur !== '' && in_array($valeur[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$valeur;
        }

        return $valeur;
    }
}
