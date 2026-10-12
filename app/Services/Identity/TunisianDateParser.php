<?php

namespace App\Services\Identity;

/**
 * Dates CIN tunisienne (§12) : la carte affiche la date en TOUTES
 * LETTRES sur la face arabe (« 06 فيفري 1999 ») et en chiffres sur la
 * face française. Format interne UNIQUE : Y-m-d (stockage DATE).
 *
 * Supporté :
 *   06 فيفري 1999 | 6 فيفرى 1999 | 06/02/1999 | 06-02-1999
 *   06 02 1999    | 1999-02-06   | 06 Février 1999
 *
 * Mois tunisiens (janvier→جانفي…) + mois français + variantes OCR
 * (فيفرى, فيفري, hamza/diacritiques — tolérés via ArabicNormalizer).
 *
 * Validation : date réelle (checkdate), année plausible,
 * JAMAIS de date future. Échec → null + avertissement amont :
 * aucune valeur n'est jamais inventée.
 */
final class TunisianDateParser
{
    /**
     * Mois tunisiens (graphie arabe) → numéro. Les variantes OCR sont
     * résolues par comparaison normalisée (ArabicNormalizer).
     */
    private const MOIS_TUNISIENS = [
        'جانفي' => 1, 'فيفري' => 2, 'مارس' => 3, 'أفريل' => 4,
        'ماي' => 5, 'جوان' => 6, 'جويلية' => 7, 'أوت' => 8,
        'سبتمبر' => 9, 'أكتوبر' => 10, 'نوفمبر' => 11, 'ديسمبر' => 12,
        // Mois arabes standards (utilisés par les modèles IA/VLM)
        'يناير' => 1, 'فبراير' => 2, 'أبريل' => 4, 'مايو' => 5,
        'يونيو' => 6, 'يوليو' => 7, 'أغسطس' => 8,
    ];

    /** Mois français (face latine) + variantes sans accents. */
    private const MOIS_FRANCAIS = [
        'janvier' => 1, 'fevrier' => 2, 'février' => 2, 'mars' => 3,
        'avril' => 4, 'mai' => 5, 'juin' => 6, 'juillet' => 7,
        'aout' => 8, 'août' => 8, 'septembre' => 9, 'octobre' => 10,
        'novembre' => 11, 'decembre' => 12, 'décembre' => 12,
    ];

    /** Bornes de plausibilité de l'année de naissance. */
    private const ANNEE_MIN = 1900;

    /**
     * Parse une valeur brute OCR → Y-m-d, ou null si non exploitable.
     */
    public function parse(?string $valeur): ?string
    {
        if ($valeur === null || trim($valeur) === '') {
            return null;
        }

        // Chiffres arabo-indiques → latins AVANT tout (§8/§21)
        $texte = ArabicNormalizer::versChiffresLatins(trim($valeur));

        // 1) Format numérique : 06/02/1999, 06-02-1999, 06 02 1999, 1999-02-06
        if (($date = $this->parseNumerique($texte)) !== null) {
            return $date;
        }

        // 2) Format en toutes lettres : « 06 فيفري 1999 » / « 06 Février 1999 »
        return $this->parseMoisEnLettres($texte);
    }

    /**
     * Valide la date finale : réelle, plausible, non future.
     */
    public function valider(?string $yMd): bool
    {
        if ($yMd === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $yMd)) {
            return false;
        }

        [$annee, $mois, $jour] = array_map('intval', explode('-', $yMd));

        if (! checkdate($mois, $jour, $annee)) {
            return false;
        }

        if ($annee < self::ANNEE_MIN) {
            return false;
        }

        return $yMd <= date('Y-m-d');
    }

    private function parseNumerique(string $texte): ?string
    {
        // dd/mm/yyyy (séparateurs / - . ou espaces)
        if (preg_match('/\b(\d{1,2})[\/\-\s.]+(\d{1,2})[\/\-\s.]+(\d{4})\b/u', $texte, $m)) {
            $jour = (int) $m[1];
            $mois = (int) $m[2];
            $annee = (int) $m[3];

            if (checkdate($mois, $jour, $annee)) {
                return sprintf('%04d-%02d-%02d', $annee, $mois, $jour);
            }
        }

        // yyyy-mm-dd
        if (preg_match('/\b(\d{4})[\/\-\s.]+(\d{1,2})[\/\-\s.]+(\d{1,2})\b/u', $texte, $m)) {
            $annee = (int) $m[1];
            $mois = (int) $m[2];
            $jour = (int) $m[3];

            if (checkdate($mois, $jour, $annee)) {
                return sprintf('%04d-%02d-%02d', $annee, $mois, $jour);
            }
        }

        return null;
    }

    /**
     * « 06 فيفري 1999 » : jour + mois en lettres + année. Le mois est
     * reconnu par comparaison NORMALISÉE (diacritiques/hamza/ى tolérés).
     */
    private function parseMoisEnLettres(string $texte): ?string
    {
        // Jour (1-2 chiffres) … année (4 chiffres), mois entre les deux
        if (! preg_match('/(\d{1,2})\s*[\s]?\s*([^\d]+?)\s*(\d{4})/u', $texte, $m)) {
            return null;
        }

        $jour = (int) $m[1];
        $moisTexte = trim($m[2]);
        $annee = (int) $m[3];

        $mois = $this->resoudreMois($moisTexte);

        if ($mois === null || $jour < 1 || $jour > 31) {
            return null;
        }

        if (! checkdate($mois, $jour, $annee)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $annee, $mois, $jour);
    }

    /**
     * Résout un mois en toutes lettres (arabe tunisien ou français,
     * variantes OCR comprises) → numéro 1-12, sinon null.
     */
    private function resoudreMois(string $moisTexte): ?int
    {
        $normalise = ArabicNormalizer::normaliserPourComparaison($moisTexte);

        foreach (self::MOIS_TUNISIENS as $nom => $numero) {
            if ($normalise === ArabicNormalizer::normaliserPourComparaison($nom)) {
                return $numero;
            }
        }

        $latin = mb_strtolower(ArabicNormalizer::normaliser($moisTexte));

        foreach (self::MOIS_FRANCAIS as $nom => $numero) {
            if ($latin === $nom || $latin === $this->sansAccents($nom)) {
                return $numero;
            }
        }

        return null;
    }

    private function sansAccents(string $texte): string
    {
        return iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texte) ?: $texte;
    }
}
