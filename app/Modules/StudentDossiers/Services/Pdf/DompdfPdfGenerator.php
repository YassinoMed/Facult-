<?php

namespace App\Modules\StudentDossiers\Services\Pdf;

use App\Modules\StudentDossiers\Contracts\PdfGeneratorInterface;
use App\Support\ArabicPdfShaper;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Moteur dompdf (§3 : dompdf/dompdf ^3.1, PHP 8.4, sans wrapper Laravel
 * pour rester compatible Laravel 13). Rendu local uniquement
 * (isRemoteEnabled=false) : aucune ressource distante, aucun appel
 * réseau pendant la génération.
 *
 * Support arabe complet : mise en forme contextuelle des glyphes (Presentation
 * Forms-B) et réordonnancement BiDi automatique via ArabicPdfShaper.
 *
 * Flux non compressés (`compress => 0`) : structure du document
 * inspectable. Note : DejaVu Sans est sous-ensemblées, le texte des
 * flux reste encodé en glyphes — les assertions de contenu portent
 * donc sur la source HTML déterministe, le binaire étant vérifié
 * structurellement (%PDF, SHA-256, headers).
 */
class DompdfPdfGenerator implements PdfGeneratorInterface
{
    public function generer(string $html, array $options = []): string
    {
        $html = ArabicPdfShaper::preparerHtmlPourDompdf($html);

        $config = new Options([
            'defaultFont' => 'DejaVu Sans',
            'isRemoteEnabled' => false,
            'isHtml5ParserEnabled' => true,
        ]);

        $moteur = new Dompdf($config);
        $moteur->loadHtml($html, 'UTF-8');
        $moteur->setPaper(
            $options['paper'] ?? 'A4',
            $options['orientation'] ?? 'portrait',
        );
        $moteur->render();

        return $moteur->output(['compress' => 0]);
    }
}
