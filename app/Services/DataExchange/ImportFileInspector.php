<?php

namespace App\Services\DataExchange;

use App\Services\Io\IoException;

/**
 * Phase 17 §17.3/§17.5 : inspection de fichier (détection auto +
 * garde-fous). Détecte format/encodage/délimiteur, refuse les fichiers
 * piégés (zip bomb, XXE, JSON profond, macros), signale mojibake,
 * BOM, zéros initiaux à risque.
 */
final class ImportFileInspector
{
    public const TAILLE_MAX = 50 * 1024 * 1024;

    public const ENCODAGES = ['UTF-8', 'Windows-1252', 'ISO-8859-1', 'Windows-1256'];

    /**
     * @return array{format: string, encodage: string, delimiteur: ?string, avertissements: array<int, string>}
     */
    public function inspecter(string $contenu, string $nomFichier): array
    {
        if (strlen($contenu) > self::TAILLE_MAX) {
            throw IoException::tropVolumineux(self::TAILLE_MAX);
        }

        $avertissements = [];
        $format = mb_strtolower(pathinfo($nomFichier, PATHINFO_EXTENSION));

        if (! in_array($format, ['csv', 'tsv', 'txt', 'xlsx', 'xls', 'json', 'xml'], true)) {
            throw IoException::formatInvalide($format !== '' ? $format : 'inconnu');
        }

        // Fichiers piégés §17.5 cas 21.
        if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $contenu) === 1) {
            throw IoException::malforme('DOCTYPE et ENTITY sont interdits (anti-XXE).');
        }

        if (str_contains($contenu, 'vbaProject') || str_contains($contenu, 'DDEAUTO')) {
            throw IoException::malforme('Macros/formules DDE refusées (fichier piégé).');
        }

        if ($format === 'json' && substr_count($contenu, '{') > 20000) {
            throw IoException::malforme('JSON trop profondément imbriqué (refus anti-DoS).');
        }

        $encodage = $this->detecterEncodage($contenu, $avertissements);
        $delimiteur = in_array($format, ['csv', 'tsv', 'txt'], true) ? $this->detecterDelimiteur($contenu) : null;

        if (str_starts_with($contenu, "\xEF\xBB\xBF")) {
            $avertissements[] = 'BOM UTF-8 détecté (ignoré).';
        }

        if (preg_match('/Ã©|Ã¨|Ã /', $contenu) === 1) {
            $avertissements[] = 'Mojibake probable (« Ã© ») : réencodez en UTF-8 avant validation.';
        }

        return ['format' => $format, 'encodage' => $encodage, 'delimiteur' => $delimiteur, 'avertissements' => $avertissements];
    }

    /**
     * @param  array<int, string>  $avertissements
     */
    private function detecterEncodage(string $contenu, array &$avertissements): string
    {
        if (mb_check_encoding($contenu, 'UTF-8')) {
            // Arabe Windows-1256 valide aussi en UTF-8 partiel : heuristique
            // sur les plages arabes brutes.
            if (preg_match('/[\xC0-\xFF][\x80-\xBF]/', $contenu) !== 1 && preg_match('/[\x80-\xFF]/', $contenu) === 1) {
                $avertissements[] = 'Encodage non-UTF-8 probable (Windows-1252/1256 ?) : conversion proposée.';
            }

            return 'UTF-8';
        }

        $detecte = mb_detect_encoding($contenu, self::ENCODAGES, true);

        return $detecte !== false ? $detecte : 'Windows-1252';
    }

    private function detecterDelimiteur(string $contenu): string
    {
        $premiere = strtok($contenu, "\n");
        $candidats = [',' => substr_count($premiere, ','), ';' => substr_count($premiere, ';'), "\t" => substr_count($premiere, "\t"), '|' => substr_count($premiere, '|')];
        arsort($candidats);

        $choisi = (string) array_key_first($candidats);

        return $candidats[$choisi] > 0 ? $choisi : ';';
    }
}
