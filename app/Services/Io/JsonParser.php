<?php

namespace App\Services\Io;

use Illuminate\Http\UploadedFile;

/**
 * Parser JSON — strict : tableau d'objets attendu.
 */
class JsonParser implements ParsesFiles
{
    public function format(): string
    {
        return 'json';
    }

    public function parse(UploadedFile $fichier): array
    {
        $contenu = $fichier->getContent();

        try {
            $decode = json_decode($contenu, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw IoException::malforme('JSON invalide.');
        }

        if (! array_is_list($decode)) {
            throw IoException::malforme('le JSON doit être un tableau d\'objets.');
        }

        // Plafond anti-DoS (CPU/RAM) avant normalisation.
        $maxLignes = max(1, (int) config('integrations.import_max_rows', 10000));

        if (count($decode) > $maxLignes) {
            throw IoException::malforme("le fichier dépasse la limite de {$maxLignes} enregistrements.");
        }

        $lignes = [];
        foreach ($decode as $ligne) {
            if (! is_array($ligne)) {
                throw IoException::malforme('chaque élément du tableau doit être un objet.');
            }

            $normalisee = [];
            foreach ($ligne as $cle => $valeur) {
                // §100 : les métadonnées techniques (_template, _exemple) sont ignorées
                if (str_starts_with((string) $cle, '_')) {
                    continue;
                }
                $normalisee[$this->normaliserCle((string) $cle)] = $valeur;
            }
            if ($normalisee !== []) {
                $lignes[] = $normalisee;
            }
        }

        return $lignes;
    }

    private function normaliserCle(string $cle): string
    {
        return NormaliseurEnTetes::cle($cle) ?: $cle;
    }
}
