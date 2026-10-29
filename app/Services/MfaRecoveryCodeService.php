<?php

namespace App\Services;

use App\Models\User;

/**
 * Codes de secours MFA (LOOP-SECURITY-REMEDIATION-01) : filet de
 * récupération à usage unique lorsque l'application TOTP est perdue.
 *
 * Les codes sont affichés UNE seule fois (flash de session), stockés
 * hachés (HMAC-SHA256 clé applicative) dans une colonne chiffrée : la
 * base ne permet ni de les lire ni de les rejouer. Chaque code est
 * consommé à la première utilisation valide.
 */
final class MfaRecoveryCodeService
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sans 0/O/1/I

    private const LONGUEUR = 10;

    /**
     * (Re)génère un jeu de codes pour l'utilisateur et retourne les codes
     * EN CLAIR (à afficher immédiatement, jamais re-stockés en clair).
     *
     * @return array<int, string>
     */
    public function generer(User $utilisateur, ?int $nombre = null): array
    {
        $nombre = max(4, $nombre ?? (int) config('security.mfa_recovery_codes_count', 8));

        $codes = [];
        $empreintes = [];

        for ($i = 0; $i < $nombre; $i++) {
            $code = $this->codeAleatoire();
            $codes[] = $code;
            $empreintes[] = $this->empreinte($code);
        }

        $utilisateur->forceFill(['mfa_recovery_codes' => $empreintes])->save();

        return $codes;
    }

    /**
     * Consomme un code s'il est valide (usage unique). Retourne false
     * sans rien modifier sinon — jamais d'exception vers l'utilisateur.
     */
    public function consommer(User $utilisateur, string $code): bool
    {
        $empreintes = (array) ($utilisateur->mfa_recovery_codes ?? []);

        if ($empreintes === []) {
            return false;
        }

        $attendue = $this->empreinte($code);

        foreach ($empreintes as $index => $empreinte) {
            if (is_string($empreinte) && $empreinte !== '' && hash_equals($empreinte, $attendue)) {
                unset($empreintes[$index]);

                $utilisateur->forceFill([
                    'mfa_recovery_codes' => array_values($empreintes),
                ])->save();

                return true;
            }
        }

        return false;
    }

    public function restants(User $utilisateur): int
    {
        return count((array) ($utilisateur->mfa_recovery_codes ?? []));
    }

    private function codeAleatoire(): string
    {
        $brut = '';

        for ($i = 0; $i < self::LONGUEUR; $i++) {
            $brut .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return substr($brut, 0, 5).'-'.substr($brut, 5);
    }

    private function empreinte(string $code): string
    {
        $normalise = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));

        return hash_hmac('sha256', $normalise, (string) config('app.key'));
    }
}
