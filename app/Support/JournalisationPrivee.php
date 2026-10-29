<?php

namespace App\Support;

/**
 * Hygiène des journaux (LOOP 9) : stratégie centralisée anti-PII.
 *
 * Règles :
 * - détection par NOM DE CLÉ (insensible à la casse, sans séparateurs),
 *   jamais par expression régulière balayant tout le log après écriture ;
 * - valeurs contenant un hash bcrypt (`$2y$`) toujours masquées
 *   (garde-fou valeur, les hashes ne sont jamais une information de debug) ;
 * - le reste est conservé tel quel (pas de faux positifs : emails,
 *   CIN hors clé sensible et textes libres restent lisibles — c'est au
 *   code appelant de ne pas les logger, voir CinOcrService) ;
 * - `empreinte()` permet de corréler sans exposer (sha256).
 */
class JournalisationPrivee
{
    /**
     * Fragments de clés considérés sensibles (comparaison en minuscules,
     * sans `_`, `-` ni espaces).
     *
     * @var array<int, string>
     */
    private const FRAGMENTS_SENSIBLES = [
        'password',
        'motdepasse',
        'passwd',
        'pwd',
        'cin',
        'identifiantnational',
        'telephone',
        'tel',
        'phone',
        'token',
        'secret',
        'cookie',
        'authorization',
        'session',
        'datenaissance',
        'lieunaissance',
        'pin',
        'otp',
        'codeverification',
        'codevalidation',
        'jeton',
        'codereinitialisation',
    ];

    public const MASQUE = '[MASQUÉ]';

    public const HASH_MASQUE = '[HASH MASQUÉ]';

    /**
     * Assainit récursivement tableaux et valeurs (les objets non
     * tableaux sont retournés tels quels, sauf les Stringable simples).
     */
    public static function assainir(mixed $donnees): mixed
    {
        if (is_array($donnees)) {
            $propre = [];
            foreach ($donnees as $cle => $valeur) {
                $propre[$cle] = self::cleSensible((string) $cle)
                    ? self::MASQUE
                    : self::assainir($valeur);
            }

            return $propre;
        }

        if (is_string($donnees) && str_contains($donnees, '$2y$')) {
            return self::HASH_MASQUE;
        }

        return $donnees;
    }

    /**
     * Empreinte sha256 pour corréler sans exposer (null conservé).
     */
    public static function empreinte(?string $valeur): ?string
    {
        if ($valeur === null || $valeur === '') {
            return null;
        }

        return hash('sha256', $valeur);
    }

    private static function cleSensible(string $cle): bool
    {
        $normalisee = mb_strtolower(str_replace(['_', '-', ' '], '', $cle));

        foreach (self::FRAGMENTS_SENSIBLES as $fragment) {
            if ($fragment !== '' && str_contains($normalisee, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
