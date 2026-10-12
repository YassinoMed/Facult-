<?php

namespace App\Services\DataExchange;

/**
 * Phase 17 §17.5 cas 1-3/13/20 : normalisation des valeurs importées.
 * Zéros initiaux préservés (texte forcé), dates Excel/séries/ambiguës,
 * chiffres arabo-indiens, nombres à virgule, statuts de notes,
 * téléphones +216, Unicode NFC, caractères invisibles.
 */
final class ValueNormalizer
{
    public const STATUTS_NOTES = [
        'ABS' => 'absent', 'ABJ' => 'abandon', 'DISP' => 'dispense',
        'NR' => 'non_renseigne', 'AJ' => 'ajourne',
    ];

    public static function texte(string $valeur): string
    {
        $valeur = normalizer_normalize(trim($valeur), \Normalizer::FORM_C) ?: trim($valeur);
        // Zéro-width, insécables, emoji de contrôle.
        $valeur = (string) preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}]/u', ' ', $valeur);

        return (string) preg_replace('/\s+/', ' ', $valeur);
    }

    /**
     * Convertit chiffres arabo-indiens (٠١٢٣٤٥٦٧٨٩) + arabes étendus.
     */
    public static function chiffresLatins(string $valeur): string
    {
        $arabes = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

        return str_replace($arabes, ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $valeur);
    }

    /**
     * @return array{date: ?string, avertissement: ?string} date ISO ou null
     */
    public static function date(string $valeur): array
    {
        $valeur = self::chiffresLatins(trim($valeur));

        // Série Excel (1900) : 1 = 01/01/1900.
        if (preg_match('/^\d{4,6}$/', $valeur) === 1 && (int) $valeur > 20000 && (int) $valeur < 80000) {
            $base = new \DateTimeImmutable('1899-12-30');

            return ['date' => $base->modify('+'.((int) $valeur).' days')->format('Y-m-d'), 'avertissement' => 'Date Excel convertie (base 1900).'];
        }

        // Textuel FR/AR : « 3 avril 2026 », « ٣ أبريل 2026 ».
        $mois = [
            'janvier' => '01', 'fevrier' => '02', 'février' => '02', 'mars' => '03', 'avril' => '04',
            'mai' => '05', 'juin' => '06', 'juillet' => '07', 'aout' => '08', 'août' => '08',
            'septembre' => '09', 'octobre' => '10', 'novembre' => '11', 'decembre' => '12', 'décembre' => '12',
            'يناير' => '01', 'فبراير' => '02', 'مارس' => '03', 'أبريل' => '04', 'ابريل' => '04',
            'مايو' => '05', 'يونيو' => '06', 'يوليو' => '07', 'أوت' => '08', 'سبتمبر' => '09',
            'أكتوبر' => '10', 'اكتوبر' => '10', 'نوفمبر' => '11', 'ديسمبر' => '12',
        ];

        foreach ($mois as $nom => $numero) {
            if (preg_match('/^(\d{1,2})\s+'.preg_quote($nom, '/').'\s+(\d{4})$/iu', $valeur, $m) === 1) {
                return ['date' => sprintf('%04d-%02d-%02d', (int) $m[2], (int) $numero, (int) $m[1]), 'avertissement' => null];
            }
        }

        foreach (['d/m/Y' => '/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', 'Y-m-d' => '/^(\d{4})-(\d{1,2})-(\d{1,2})$/'] as $format => $motif) {
            if (preg_match($motif, $valeur, $m) === 1) {
                $dt = \DateTimeImmutable::createFromFormat($format === 'd/m/Y' ? '!d/m/Y' : '!Y-m-d', $valeur);

                if ($dt !== false) {
                    $avertissement = null;

                    if ($format === 'd/m/Y' && (int) $m[1] <= 12 && (int) $m[2] <= 12 && $m[1] !== $m[2]) {
                        $avertissement = "Date ambiguë « {$valeur} » interprétée jj/mm/aaaa.";
                    }

                    return ['date' => $dt->format('Y-m-d'), 'avertissement' => $avertissement];
                }
            }
        }

        return ['date' => null, 'avertissement' => "Date illisible : « {$valeur} »."];
    }

    /**
     * @return array{valeur: float|string|null, avertissement: ?string}
     */
    public static function nombre(string $valeur): array|string|float|null
    {
        $brute = trim($valeur);
        $haut = mb_strtoupper(self::chiffresLatins($brute));

        if (array_key_exists($haut, self::STATUTS_NOTES)) {
            return ['valeur' => self::STATUTS_NOTES[$haut], 'avertissement' => "Statut spécial « {$brute} » mappé."];
        }

        $sansEspace = str_replace(["\u{00A0}", "\u{202F}", ' ', "'"], '', self::chiffresLatins($brute));

        // « 12,5 » vs « 12.5 », pourcentages, unités.
        $pourcent = str_ends_with($sansEspace, '%');
        $sansEspace = rtrim($sansEspace, '%');
        $sansEspace = (string) preg_replace('/(dt|tnd|dhs?|€)$/i', '', $sansEspace);
        $sansEspace = str_replace(',', '.', $sansEspace);

        if (! is_numeric($sansEspace)) {
            return ['valeur' => null, 'avertissement' => "Nombre illisible : « {$brute} »."];
        }

        $nombre = (float) $sansEspace;

        return ['valeur' => $pourcent ? $nombre / 100 : $nombre, 'avertissement' => null];
    }

    public static function telephone(string $valeur): string
    {
        $chiffres = self::chiffresLatins($valeur);
        $chiffres = (string) preg_replace('/[^\d+]/', '', $chiffres);

        if (str_starts_with($chiffres, '00216')) {
            $chiffres = '+216'.substr($chiffres, 5);
        } elseif (preg_match('/^2\d{7}$/', $chiffres) === 1 || preg_match('/^[4579]\d{7}$/', $chiffres) === 1) {
            $chiffres = '+216'.$chiffres;
        }

        return $chiffres;
    }

    /**
     * Neutralise l'injection de formules CSV/Excel (§17.4) : =, +, -, @, \t, \r.
     */
    public static function celluleExport(string $valeur): string
    {
        if (preg_match('/^[=+\-@\t\r]/', $valeur) === 1) {
            return "'".$valeur;
        }

        return $valeur;
    }

    /**
     * Normalisation d'une CIN tunisienne (§3) :
     * 8 chiffres ASCII stricts, préservation des zéros initiaux.
     */
    public static function cin(string $valeur): ?string
    {
        $valeur = self::chiffresLatins(trim($valeur));
        $chiffres = (string) preg_replace('/\s+/', '', $valeur);

        if (preg_match('/\A[0-9]{8}\z/', $chiffres) === 1) {
            return $chiffres;
        }

        return null;
    }
}
