<?php

namespace App\Services\Identity\Engines;

use App\Services\Identity\OcrEngineInterface;

/**
 * Moteur OCR par défaut (placeholder) : aucun moteur réel configuré.
 * Retourne un texte vide — le pipeline reste fail-soft, la saisie
 * manuelle prend le relais. Remplaçable par un vrai moteur via
 * IDENTITY_OCR_DRIVER sans toucher au reste de l'application.
 */
class NullOcrEngine implements OcrEngineInterface
{
    public function rawText(string $filePath, string $langue = OcrEngineInterface::LANGUE_ARABE_LATIN, ?int $psm = null): string
    {
        return '';
    }
}
