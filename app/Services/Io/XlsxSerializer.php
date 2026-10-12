<?php

namespace App\Services\Io;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Sérialiseur XLSX via OpenSpout (écriture en flux).
 * Toutes les cellules sont écrites en TEXTE pur : les formules ne sont
 * jamais évaluées, et la formula injection est neutralisée (§63).
 */
class XlsxSerializer implements SerializesExports
{
    public function format(): string
    {
        return 'xlsx';
    }

    public function mimeType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function serialize(array $entetes, array $lignes): string
    {
        $options = new Options;
        $writer = new Writer($options);

        $fichierTemporaire = tempnam(sys_get_temp_dir(), 'export_xlsx_').'.xlsx';
        $writer->openToFile($fichierTemporaire);

        $csvGuard = new CsvSerializer;

        $writer->addRow(Row::fromValues($entetes));

        foreach ($lignes as $ligne) {
            $valeurs = [];
            foreach ($entetes as $entete) {
                $valeurs[] = $csvGuard->neutraliserFormule((string) ($ligne[$entete] ?? ''));
            }
            $writer->addRow(Row::fromValues($valeurs));
        }

        $writer->close();

        $contenu = (string) file_get_contents($fichierTemporaire);
        @unlink($fichierTemporaire);

        return $contenu;
    }
}
