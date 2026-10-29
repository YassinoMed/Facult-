<?php

namespace App\Services\Notifications\Support;

/**
 * Utilitaire de détection et masquage des données sensibles et PII (§12-15).
 * Empêche toute fuite de secret, jeton, CIN, mot de passe ou coordonnées bancaires
 * dans les journaux, les notifications externes ou les métadonnées outbox.
 */
class MasqueurDonneesSensibles
{
    /** Clés réputées hautement confidentielles dans les tableaux. */
    public const CLES_SENSIBLES = [
        'password', 'mot_de_passe', 'secret', 'token', 'access_token',
        'api_key', 'cle_api', 'cin', 'iban', 'cvv', 'carte_bancaire',
        'code_recuperation', 'mfa_secret',
    ];

    /**
     * Masque les informations confidentielles au sein d'une chaîne de caractères.
     */
    public static function masquerTexte(string $texte): string
    {
        // Masquage des jetons Bearer
        $texte = preg_replace('/Bearer\s+[A-Za-z0-9\-\._~\+\/]+=*/i', 'Bearer [MASQUÉ]', $texte) ?? $texte;

        // Masquage des numéros de carte bancaire (16 chiffres séparés par espaces ou tirets)
        $texte = preg_replace('/\b\d{4}[ -]?\d{4}[ -]?\d{4}[ -]?(\d{4})\b/', '****-****-****-$1', $texte) ?? $texte;

        // Masquage des IBAN
        $texte = preg_replace('/\b[A-Z]{2}\d{2}[A-Z0-9]{4}\d{7}([A-Z0-9]{0,16})\b/i', 'IBAN-****-****', $texte) ?? $texte;

        // Masquage des numéros de CIN tunisiens (8 chiffres stricts précédés d'un mot-clé)
        $texte = preg_replace('/(CIN\s*[:=]?\s*)\d{8}/i', '$1********', $texte) ?? $texte;

        return $texte;
    }

    /**
     * Nettoie récursivement un tableau de données en masquant les clés sensibles.
     *
     * @param  array<string, mixed>  $donnees
     * @return array<string, mixed>
     */
    public static function masquerTableau(array $donnees): array
    {
        $nettoye = [];

        foreach ($donnees as $cle => $valeur) {
            $cleMin = strtolower((string) $cle);

            // Vérification si la clé contient un terme sensible
            $estSensible = false;
            foreach (self::CLES_SENSIBLES as $sensible) {
                if (str_contains($cleMin, $sensible)) {
                    $estSensible = true;
                    break;
                }
            }

            if ($estSensible) {
                if (is_string($valeur) && strlen($valeur) >= 8) {
                    $nettoye[$cle] = substr($valeur, 0, 2).'****'.substr($valeur, -2);
                } else {
                    $nettoye[$cle] = '***MASQUÉ***';
                }
            } elseif (is_array($valeur)) {
                $nettoye[$cle] = self::masquerTableau($valeur);
            } elseif (is_string($valeur)) {
                $nettoye[$cle] = self::masquerTexte($valeur);
            } else {
                $nettoye[$cle] = $valeur;
            }
        }

        return $nettoye;
    }
}
