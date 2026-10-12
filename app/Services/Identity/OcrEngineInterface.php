<?php

namespace App\Services\Identity;

/**
 * Moteur OCR brut : reconnu le TEXTE d'un document (image/PDF).
 * Interchangeable via config/identity.php (`identity.ocr.driver`) —
 * remplacer le moteur ne touche AUCUNE autre couche de l'application.
 */
interface OcrEngineInterface
{
    /**
     * Indice de langue par défaut (§1) : CIN tunisienne = arabe ET
     * latin simultanés (العربية + chiffres latins + mentions latines).
     */
    public const LANGUE_ARABE_LATIN = 'ara+lat';

    /**
     * Texte brut reconnu dans le fichier (lignes concaténées par \n).
     * Ne lève jamais d'exception sur contenu illisible : retourne ''
     * (le pipeline OCR est fail-soft, la saisie manuelle reste possible).
     *
     * @param  string  $langue  code(s) de langue du moteur, résolu(s)
     *                          depuis config/ocr.php par l'appelant (§3)
     * @param  int|null  $psm  mode de segmentation Tesseract — null =
     *                         valeur par défaut du moteur (utile pour
     *                         l'OCR par zones §5 : 6 bloc, 7 ligne, 11 dispersé)
     */
    public function rawText(string $filePath, string $langue = self::LANGUE_ARABE_LATIN, ?int $psm = null): string;
}
