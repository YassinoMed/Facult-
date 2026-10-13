<?php

namespace Tests\Unit;

use App\Services\MfaTotpService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * TOTP : vecteurs officiels RFC 6238 (SHA1, graine ASCII
 * "12345678901234567890"), réduits à 6 chiffres par modulo.
 */
class MfaTotpServiceTest extends TestCase
{
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /**
     * @return array<string, array{int, string}>
     */
    public static function vecteursRfc(): array
    {
        return [
            'T=59' => [59, '287082'],
            'T=1111111109' => [1111111109, '081804'],
            'T=1111111111' => [1111111111, '050471'],
            'T=1234567890' => [1234567890, '005924'],
            'T=2000000000' => [2000000000, '279037'],
            'T=20000000000' => [20000000000, '353130'],
        ];
    }

    #[DataProvider('vecteursRfc')]
    public function test_vecteurs_rfc_6238(int $temps, string $attendu): void
    {
        $service = new MfaTotpService;

        $this->assertSame($attendu, $service->calculer(self::SECRET, (int) floor($temps / 30)));
        $this->assertTrue($service->verifier(self::SECRET, $attendu, $temps));
    }

    public function test_rejet_hors_fenetre_et_format(): void
    {
        $service = new MfaTotpService;

        $this->assertFalse($service->verifier(self::SECRET, '287082', 59 + 90));
        $this->assertFalse($service->verifier(self::SECRET, 'abcdef', 59));
        $this->assertFalse($service->verifier(self::SECRET, '28708', 59));
        $this->assertTrue($service->verifier(self::SECRET, '287082', 59 + 30));
    }

    public function test_secret_aleatoire_et_base32_roundtrip(): void
    {
        $service = new MfaTotpService;
        $secret = $service->genererSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        $this->assertSame(
            $service->calculer($secret, 42),
            $service->calculer($secret, 42)
        );
        $this->assertStringStartsWith('otpauth://totp/', $service->uriProvisionnement($secret, 'admin@univ.test'));
    }
}
