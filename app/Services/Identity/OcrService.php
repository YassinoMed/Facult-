<?php

namespace App\Services\Identity;

use App\Services\Identity\Engines\ColabOcrEngine;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrateur OCR (§1-4) : moteur brut (interchangeable, indice de
 * langue §1) → détection du type → pipeline dédié (CIN arabe via
 * ArabicOcrService, passeport via PassportParser) → normalisation →
 * OcrResult riche.
 *
 * Règles absolues :
 * - fail-soft : un OCR muet ou illisible ne bloque JAMAIS l'inscription
 *   (la saisie manuelle reste le parcours de repli) ;
 * - AUCUNE donnée OCR n'est journalisée (§15) — seuls type de document,
 *   langue et durée sont tracés, sans contenu ;
 * - l'OCR n'est jamais une preuve d'identité : la validation humaine
 *   reste obligatoire en aval (§18).
 */
class OcrService implements OcrServiceInterface
{
    public function __construct(private readonly OcrEngineInterface $engine) {}

    public function extract(string $filePath, string $documentType, string $langue = self::LANGUE_AUTO): OcrResult
    {
        $debut = microtime(true);

        // §3 : la langue est résolue depuis config/ocr.php (jamais en
        // dur) ; le pipeline CIN gère lui-même zones/variantes.
        $texteBrut = $this->engine->rawText($filePath, $this->resoudreLangue($documentType, $langue));

        // Détection serveur : la MRZ passeport prime sur le choix déclaré.
        $typeDetecte = $this->detectDocumentType($texteBrut) ?? $documentType;
        $typeUtilise = in_array($typeDetecte, ['cin', 'passeport'], true) ? $typeDetecte : $documentType;

        $resultat = $typeUtilise === 'passeport'
            ? $this->parsePasseport($texteBrut)
            // Pipeline SPÉCIALISÉ CIN tunisienne (§26) : si moteur distant Colab,
            // on évite 36 requêtes HTTP sur le tunnel en utilisant le texte brut
            // extrait de l'image complète. Les moteurs locaux (ppocr, tesseract)
            // conservent le multi-variantes + découpage par zones.
            : ($this->engine instanceof ColabOcrEngine
                ? app(CinOcrService::class)->depuisTexte($texteBrut)
                : app(CinOcrService::class)->extraire($filePath, $langue));

        // Traçabilité minimale (§29) : type + langue + durée, JAMAIS
        // de contenu OCR (arabe compris) hors debug local explicit.
        Log::notice('identity.ocr.completed', [
            'type_demande' => $documentType,
            'type_utilise' => $typeUtilise,
            'langue' => $resultat->language,
            'duree_ms' => (int) ((microtime(true) - $debut) * 1000),
        ]);

        return $resultat;
    }

    /**
     * §3 — résolution centralisée des langues via config/ocr.php.
     */
    private function resoudreLangue(string $documentType, string $hint): string
    {
        if ($hint !== self::LANGUE_AUTO && $hint !== '' && ! str_contains($hint, '+lat')) {
            return (string) $hint;
        }

        return (string) match ($documentType) {
            'passeport' => config('ocr.passeport_language', 'eng+fra'),
            default => config('ocr.cin_language', 'ara+eng'),
        };
    }

    /**
     * Détection du type réel : MRZ TD3 ⇒ passeport.
     */
    public function detectDocumentType(string $texteBrut): ?string
    {
        if (MrzParser::parse($texteBrut) !== null) {
            return 'passeport';
        }

        return null; // le CIN n'a pas de marqueur structurel fiable
    }

    private function parsePasseport(string $texteBrut): OcrResult
    {
        $parse = app(PassportParser::class)->parse($texteBrut);
        $data = $parse['data'];

        return new OcrResult(
            documentType: 'passeport',
            language: ArabicNormalizer::detecterLangue($texteBrut),
            documentNumber: $data['document_number'] ?? null,
            firstName: $data['first_name'] ?? null,
            lastName: $data['last_name'] ?? null,
            dateOfBirth: $data['date_of_birth'] ?? null,
            nationality: $data['nationality'] ?? null,
            sex: $data['sex'] ?? null,
            expiryDate: $data['expiry_date'] ?? null,
            mrz: $parse['mrz_valide'] !== null
                ? ($parse['mrz_valide'] === true ? 'valide' : 'invalide')
                : null,
            rawFields: [
                'lignes_detectees' => substr_count($texteBrut, "\n") + ($texteBrut !== '' ? 1 : 0),
            ],
            confidence: $parse['confidence'],
        );
    }
}
