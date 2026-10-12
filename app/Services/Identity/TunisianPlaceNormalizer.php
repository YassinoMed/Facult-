<?php

namespace App\Services\Identity;

use App\Models\LieuNaissance;

/**
 * Lieux de naissance tunisiens (§13) : correspondance CONTRÔLÉE en
 * priorité (table administrative extensible `lieux_naissance`),
 * JAMAIS une confiance aveugle dans une translittération automatique.
 *
 * Priorité de résolution (§13) :
 *   1. mapping officiel/contrôlé (table, correspondance normalisée
 *      + tolérance OCR : 1 faute de lettre admise — « صفافس » ≡ « صفاقس ») ;
 *   2. translittération lettre-à-lettre (fallback) ;
 *   3. la valeur arabe originale est TOUJOURS conservée (raw_ar) :
 *      l'utilisateur tranche (validation humaine obligatoire).
 *
 * La tolérance OCR reste BORNÉE (distance 1 sur la forme normalisée) :
 * au-delà, le lieu est renvoyé comme « non identifié avec certitude »
 * pour éviter toute fausse reconnaissance.
 */
final class TunisianPlaceNormalizer
{
    public function __construct(private readonly ArabicTransliterationService $transliteration) {}

    /**
     * Résout un lieu arabe brut vers sa forme latine officielle.
     * Retourne null si aucune correspondance fiable.
     */
    public function resoudre(?string $valeur): ?string
    {
        if ($valeur === null || trim($valeur) === '') {
            return null;
        }

        $brutArabe = trim((string) preg_replace('/[^\p{Arabic}\s]/u', '', $valeur));

        if ($brutArabe === '') {
            return null;
        }

        // 1) Correspondance contrôlée EXACTE (clé normalisée)
        $exact = LieuNaissance::resoudreNomLatin($brutArabe);

        if ($exact !== null) {
            return $exact;
        }

        // 2) Tolérance OCR bornée : 1 faute de lettre max (§21)
        $approximatif = $this->resoudreApproximation($brutArabe);

        if ($approximatif !== null) {
            return $approximatif;
        }

        // 3) Repli : translittération (signalée comme non contrôlée)
        $translittère = $this->transliteration->transliterate($brutArabe);

        return $translittère !== '' ? $translittère : null;
    }

    /**
     * La résolution est-elle ISSUE de la table officielle (fiable) ou
     * d'une simple translittération (à vérifier) ?
     */
    public function estOfficiel(string $valeurArabe): bool
    {
        $brutArabe = trim((string) preg_replace('/[^\p{Arabic}\s]/u', '', $valeurArabe));

        if ($brutArabe === '') {
            return false;
        }

        if (LieuNaissance::resoudreNomLatin($brutArabe) !== null) {
            return true;
        }

        return $this->resoudreApproximation($brutArabe) !== null;
    }

    /**
     * Recherche dans la table avec tolérance OCR : distance d'édition
     * ≤ 1 sur la clé normalisée. Borné pour éviter les faux positifs.
     */
    private function resoudreApproximation(string $brutArabe): ?string
    {
        $cle = ArabicNormalizer::normaliserPourComparaison($brutArabe);
        $longueur = mb_strlen($cle);

        if ($longueur < 3) {
            return null; // trop court : tolérance refusée (ambiguïté)
        }

        foreach (LieuNaissance::query()->get(['arabic_name_key', 'official_name']) as $lieu) {
            $cleTable = ArabicNormalizer::normaliserPourComparaison($lieu->arabic_name_key);

            if (levenshtein($cle, $cleTable) <= 1) {
                return $lieu->official_name;
            }
        }

        return null;
    }
}
