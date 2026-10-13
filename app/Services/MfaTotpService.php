<?php

namespace App\Services;

/**
 * TOTP RFC 6238 / RFC 4226 en pur PHP (LOOP J) : aucune dépendance —
 * HMAC-SHA1 + troncature dynamique + base32, QR via `QrCodeSvg` existant.
 * Exactitude prouvée par les vecteurs officiels RFC 6238 (tests).
 */
final class MfaTotpService
{
    private const ALPHABET_BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const PERIODE = 30;

    private const CHIFFRES = 6;

    public function genererSecret(int $octets = 20): string
    {
        return self::encoderBase32(random_bytes($octets));
    }

    public function uriProvisionnement(string $secret, string $compte, string $emetteur = 'UniRegistre'): string
    {
        return 'otpauth://totp/'.rawurlencode($emetteur).':'.rawurlencode($compte)
            .'?secret='.$secret.'&issuer='.rawurlencode($emetteur).'&algorithm=SHA1&digits=6&period=30';
    }

    public function verifier(string $secret, string $code, ?int $maintenant = null, int $fenetre = 1): bool
    {
        $code = trim($code);

        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $compteur = (int) floor(($maintenant ?? time()) / self::PERIODE);

        for ($decalage = -$fenetre; $decalage <= $fenetre; $decalage++) {
            if (hash_equals($this->calculer($secret, $compteur + $decalage), $code)) {
                return true;
            }
        }

        return false;
    }

    public function calculer(string $secret, int $compteur): string
    {
        $clef = self::decoderBase32($secret);
        $message = pack('N*', 0, $compteur);
        $empreinte = hash_hmac('sha1', $message, $clef, true);
        $decalage = ord($empreinte[19]) & 0x0F;

        $entier = ((ord($empreinte[$decalage]) & 0x7F) << 24)
            | (ord($empreinte[$decalage + 1]) << 16)
            | (ord($empreinte[$decalage + 2]) << 8)
            | ord($empreinte[$decalage + 3]);

        return str_pad((string) ($entier % (10 ** self::CHIFFRES)), self::CHIFFRES, '0', STR_PAD_LEFT);
    }

    public static function encoderBase32(string $donnees): string
    {
        $binaire = '';
        foreach (str_split($donnees) as $octet) {
            $binaire .= str_pad(decbin(ord($octet)), 8, '0', STR_PAD_LEFT);
        }

        $sortie = '';
        foreach (str_split($binaire, 5) as $morceau) {
            $sortie .= self::ALPHABET_BASE32[bindec(str_pad($morceau, 5, '0'))];
        }

        return $sortie;
    }

    public static function decoderBase32(string $donnees): string
    {
        $donnees = strtoupper(rtrim($donnees, '='));
        $binaire = '';
        foreach (str_split($donnees) as $caractere) {
            $position = strpos(self::ALPHABET_BASE32, $caractere);
            if ($position === false) {
                throw new \InvalidArgumentException('Secret base32 invalide.');
            }
            $binaire .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $sortie = '';
        foreach (str_split($binaire, 8) as $octet) {
            if (strlen($octet) === 8) {
                $sortie .= chr(bindec($octet));
            }
        }

        return $sortie;
    }
}
