<?php

namespace App\Modules\StudentDossiers\Contracts;

/**
 * Contrat de génération PDF (§2) : le module ne connaît aucun moteur
 * (dompdf aujourd'hui, autre demain). Le filesystem n'intervient pas
 * ici — octets PDF en retour uniquement.
 */
interface PdfGeneratorInterface
{
    /**
     * @param  array{paper?: string, orientation?: string}  $options
     */
    public function generer(string $html, array $options = []): string;
}
