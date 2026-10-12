<?php

namespace App\Services\Io;

use Illuminate\Http\UploadedFile;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Parser XLSX via OpenSpout (lecture en flux — mémoire bornée,
 * AUCUNE évaluation de formule : les valeurs sont lues telles quelles).
 */
class XlsxParser implements ParsesFiles
{
    public function format(): string
    {
        return 'xlsx';
    }

    public function parse(UploadedFile $fichier): array
    {
        $cheminTemporaire = $fichier->getRealPath() ?: $fichier->getPathname();

        /*
         | §P1-9 : plafond dur de lignes de données — un XLSX de quelques Mo
         | peut se décompresser en plusieurs Go (bombe ZIP, ratio ~1000:1) ;
         | sans limite, le parsing synchrone épuiserait CPU/RAM du worker.
         */
        $maxLignes = max(1, (int) config('integrations.import_max_rows', 10000));

        $options = new Options;
        $reader = new Reader($options);

        $lignes = [];
        $entetes = null;

        $reader->open($cheminTemporaire);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $ligne) {
                    if ($ligne->isEmpty()) {
                        continue;
                    }

                    $cellules = [];
                    foreach ($ligne->toArray() as $valeur) {
                        $cellules[] = (string) $valeur;
                    }

                    // Première ligne = en-têtes
                    if ($entetes === null) {
                        $entetes = array_map(fn ($h) => $this->normaliserCle($h), $cellules);

                        // Plafond de colonnes : une seule ligne à N millions
                        // de colonnes épuiserait la RAM (array_combine).
                        if (count($entetes) > 200) {
                            throw IoException::malforme('le fichier dépasse la limite de 200 colonnes.');
                        }

                        continue;
                    }

                    if (count($lignes) >= $maxLignes) {
                        throw IoException::malforme("le fichier dépasse la limite de {$maxLignes} lignes de données.");
                    }

                    $cellules = array_pad($cellules, count($entetes), '');
                    $lignes[] = array_slice(array_combine($entetes, array_map('trim', $cellules)), 0, count($entetes));
                }

                break; // première feuille uniquement
            }
        } finally {
            $reader->close();
        }

        if ($entetes === null || $lignes === []) {
            throw IoException::malforme('le fichier XLSX doit contenir des en-têtes et au moins une ligne.');
        }

        return $lignes;
    }

    private function normaliserCle(string $cle): string
    {
        return NormaliseurEnTetes::cle($cle) ?: 'colonne_'.uniqid();
    }
}
