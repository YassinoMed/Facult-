<?php

namespace App\Services\Identity;

/**
 * MRZ « Machine Readable Zone » — doc. ICAO 9303 (TD3 : passeports,
 * 2 lignes × 44 caractères).
 *
 * Règles :
 * - le filler '<' vaut 0 ; les chiffres leur valeur ; les lettres
 *   A-Z valent 10 à 35 ;
 * - poids cycliques 7, 3, 1 appliqués de gauche à droite ;
 * - la somme modulo 10 DOIT égaler le chiffre de contrôle.
 *
 * Une MRZ à checksum valide est la donnée la plus FIABLE d'un passeport
 * (§7) — elle ne reste toutefois jamais une preuve d'identité absolue.
 */
final class MrzParser
{
    private const LONGUEUR_LIGNE = 44;

    /**
     * Détecte et parse une MRZ TD3 dans le texte OCR brut.
     *
     * @param  string  $texteBrut  texte OCR (peut contenir du bruit autour)
     * @return array{valide: bool, raison: string|null, champs: array{
     *     document_number: ?string, last_name: ?string, first_name: ?string,
     *     nationality: ?string, date_of_birth: ?string, sex: ?string,
     *     expiry_date: ?string, mrz: ?string, checksums: array<string, bool>
     * }}|null null si aucune MRZ détectée
     */
    public static function parse(string $texteBrut): ?array
    {
        $lignes = self::lignesMrz($texteBrut);

        if ($lignes === null) {
            return null;
        }

        [$ligne1, $ligne2] = $lignes;

        // ── Ligne 1 : P<UTO + NOM<<PRENOM<<<<<<<<<< ──
        $noms = self::parseIdentite(mb_substr($ligne1, 5));

        // ── Ligne 2 ──
        $numeroDocument = self::nettoyer(mb_substr($ligne2, 0, 9));
        $checkNumero = mb_substr($ligne2, 9, 1);
        $nationalite = self::codePays(mb_substr($ligne2, 10, 3));
        $dateNaissance = mb_substr($ligne2, 13, 6);
        $checkNaissance = mb_substr($ligne2, 19, 1);
        $sexe = mb_substr($ligne2, 20, 1);
        $expiration = mb_substr($ligne2, 21, 6);
        $checkExpiration = mb_substr($ligne2, 27, 1);
        $numeroPersonnel = mb_substr($ligne2, 28, 14);
        $checkPersonnel = mb_substr($ligne2, 42, 1);
        $checkComposite = mb_substr($ligne2, 43, 1);

        $checksums = [
            'document_number' => self::verifie(mb_substr($ligne2, 0, 9), $checkNumero),
            'date_of_birth' => self::verifie($dateNaissance, $checkNaissance),
            'expiry_date' => self::verifie($expiration, $checkExpiration),
            'personal_number' => self::verifie($numeroPersonnel, $checkPersonnel),
            'composite' => self::verifie(
                mb_substr($ligne2, 0, 10).$dateNaissance.$checkNaissance.$sexe
                .$expiration.$checkExpiration.$numeroPersonnel.$checkPersonnel,
                $checkComposite,
            ),
        ];

        $tousValides = ! in_array(false, $checksums, true);

        return [
            'valide' => $tousValides,
            'raison' => $tousValides
                ? null
                : 'Chiffre(s) de contrôle MRZ invalide(s) : vérification humaine requise.',
            'champs' => [
                'document_number' => $numeroDocument,
                'last_name' => $noms['last_name'],
                'first_name' => $noms['first_name'],
                'nationality' => $nationalite,
                'date_of_birth' => self::dateMrz($dateNaissance),
                'sex' => in_array($sexe, ['M', 'F'], true) ? $sexe : null,
                'expiry_date' => self::dateMrz($expiration, futur: true),
                'mrz' => $ligne1."\n".$ligne2,
                'checksums' => $checksums,
            ],
        ];
    }

    /**
     * Détecte les deux lignes d'une MRZ TD3 dans un texte bruité.
     * Prérequis strict : lignes de 44 caractères de l'alphabet MRZ,
     * la première commençant par « P< » (type document passeport).
     *
     * @return array{0: string, 1: string}|null
     */
    private static function lignesMrz(string $texteBrut): ?array
    {
        $candidats = [];

        foreach (preg_split('/\r\n|\n|\r/', $texteBrut) ?: [] as $ligne) {
            $nettoyee = mb_strtoupper(trim($ligne));

            if (preg_match('/^P<[A-Z0-9<]{42}$/', $nettoyee)) {
                $candidats[0] = $nettoyee;

                continue;
            }

            // Ligne données : caractères MRZ uniquement (pas de « P< » initial)
            if (isset($candidats[0]) && ! isset($candidats[1])
                && preg_match('/^[A-Z0-9<]{44}$/', $nettoyee)) {
                $candidats[1] = $nettoyee;
            }
        }

        if (count($candidats) < 2) {
            return null;
        }

        return [$candidats[0], $candidats[1]];
    }

    /**
     * @return array{last_name: ?string, first_name: ?string}
     */
    private static function parseIdentite(string $segment): array
    {
        $segment = self::nettoyer($segment);

        // Format : NOM<<PRENOM<PRENOM2<<<...  (séparateurs « < »)
        $parties = array_values(array_filter(explode('<<', $segment, 2), fn ($p) => $p !== ''));

        return [
            'last_name' => $parties[0] ?? null,
            'first_name' => isset($parties[1]) ? str_replace('<', ' ', $parties[1]) : null,
        ];
    }

    /**
     * Chiffre de contrôle ICAO : poids 7-3-1, mod 10.
     */
    public static function verifie(string $valeur, string $check): bool
    {
        if ($check === '' || ! preg_match('/^[0-9]$/', $check)) {
            return false;
        }

        $poids = [7, 3, 1];
        $somme = 0;

        foreach (mb_str_split($valeur) as $i => $caractere) {
            $valeurCaractere = match (true) {
                $caractere === '<' => 0,
                ctype_digit($caractere) => (int) $caractere,
                ctype_alpha($caractere) => ord(mb_strtoupper($caractere)) - 55, // A=10 … Z=35
                default => 0,
            };

            $somme += $valeurCaractere * $poids[$i % 3];
        }

        return ($somme % 10) === (int) $check;
    }

    /**
     * AAMMJJ MRZ → Y-m-d.
     * Naissance : AA > année en cours ⇒ 19xx (personne née avant 2000),
     * sinon 20xx. Expiration : toujours 20xx (passeports actuels).
     */
    private static function dateMrz(string $aammjj, bool $futur = false): ?string
    {
        if (! preg_match('/^[0-9]{6}$/', $aammjj)) {
            return null;
        }

        $annee2 = (int) mb_substr($aammjj, 0, 2);
        $mois = (int) mb_substr($aammjj, 2, 2);
        $jour = (int) mb_substr($aammjj, 4, 2);
        $siecle = $futur ? 2000 : ($annee2 > (int) date('y') ? 1900 : 2000);
        $annee = $siecle + $annee2;

        if (! checkdate($mois, $jour, $annee)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $annee, $mois, $jour);
    }

    private static function codePays(string $code): ?string
    {
        $nettoye = self::nettoyer($code);

        return $nettoye !== '' ? $nettoye : null;
    }

    /**
     * Supprime les fillers « < » en fin de segment (pas à l'intérieur).
     */
    private static function nettoyer(string $segment): string
    {
        return trim($segment, '<');
    }
}
