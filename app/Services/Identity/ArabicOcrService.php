<?php

namespace App\Services\Identity;

/**
 * Pipeline CIN tunisienne ARABE (§24) : coordonne l'extraction depuis
 * le texte brut OCR vers un OcrResult complet.
 *
 * Responsabilités séparées (§24) :
 * - CinParser            → identification des champs (libellés ar/fr) ;
 * - ArabicNormalizer      → normalisation arabe + langue ;
 * - ArabicTransliterationService → translittération noms propres ;
 * - ce service            → ASSEMBLAGE du résultat riche (langue,
 *   originaux arabes conservés §9, valeurs translittérées §11).
 *
 * §13 : champs structurés (CIN/date/sexe) → normalisation ;
 *       noms propres (nom/prénom/lieu) → TRANSLITTÉRATION, jamais de
 *       traduction sémantique.
 */
final class ArabicOcrService
{
    public function __construct(private readonly CinParser $parser) {}

    /**
     * Assemble l'OcrResult d'une CIN tunisienne depuis le texte OCR brut.
     */
    public function extraireCin(string $texteBrut): OcrResult
    {
        $langue = ArabicNormalizer::detecterLangue($texteBrut);

        $parse = $this->parser->parse($texteBrut);
        $data = $parse['data'];

        // §9/§10 : originaux arabes conservés EN PARALLÈLE de la
        // translittération (vérification humaine, audit, comparaison).
        $originals = [];
        foreach ($parse['arabic'] as $champ => $brut) {
            $originals[$champ] = [
                'raw' => $brut,
                'transliterated' => $parse['transliterated'][$champ] ?? '',
            ];
        }

        return new OcrResult(
            documentType: 'cin',
            language: $langue,
            documentNumber: $data['document_number'] ?? null,
            firstName: $data['first_name'] ?? null,
            lastName: $data['last_name'] ?? null,
            dateOfBirth: $data['date_of_birth'] ?? null,
            placeOfBirth: $data['place_of_birth'] ?? null,
            sex: $data['sex'] ?? null,
            rawFields: [
                'lignes_detectees' => substr_count($texteBrut, "\n") + ($texteBrut !== '' ? 1 : 0),
                'langue_detectee' => $langue,
            ],
            confidence: $parse['confidence'],
            originals: $originals,
        );
    }
}
