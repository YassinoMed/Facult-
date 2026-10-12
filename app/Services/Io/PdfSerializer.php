<?php

namespace App\Services\Io;

use App\Modules\StudentDossiers\Contracts\PdfGeneratorInterface;

/**
 * Sérialiseur PDF tabulaire (§36) : mêmes entêtes/lignes que les
 * autres formats (source unique), orientation auto (paysage si
 * tableau large). Via le contrat PDF partagé (Dompdf).
 */
class PdfSerializer implements SerializesExports
{
    public function __construct(private readonly PdfGeneratorInterface $pdf) {}

    public function format(): string
    {
        return 'pdf';
    }

    public function mimeType(): string
    {
        return 'application/pdf';
    }

    /** @param array<int, array<string, mixed>> $lignes */
    public function serialize(array $entetes, array $lignes): string
    {
        if ($entetes === []) {
            throw IoException::formatInvalide('pdf (aucune colonne)');
        }

        $paysage = count($entetes) > 6;
        $ths = implode('', array_map(fn ($e) => '<th>'.e((string) $e).'</th>', $entetes));
        $trs = '';

        foreach ($lignes as $ligne) {
            $tds = '';
            foreach ($entetes as $entete) {
                $tds .= '<td>'.e((string) ($ligne[$entete] ?? '')).'</td>';
            }
            $trs .= "<tr>{$tds}</tr>";
        }

        $html = '<table border="1" cellpadding="4" cellspacing="0" width="100%">'
            ."<thead><tr>{$ths}</tr></thead><tbody>{$trs}</tbody></table>"
            .'<p>Document généré le '.now()->format('d/m/Y H:i').' — '.count($lignes).' ligne(s).</p>';

        return $this->pdf->generer($html, [
            'paper' => 'A4',
            'orientation' => $paysage ? 'landscape' : 'portrait',
        ]);
    }
}
