<?php

namespace Tests\Feature;

use App\Support\QrCodeSvg;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * LOT 07 — Conformité ISO/IEC 18004 de l'encodeur QR natif.
 *
 * Les fixtures sont épinglées depuis le banc de croisement externe
 * (LOOP 07) : chaque matrice produite par ce fichier a été vérifiée
 * bit-à-bit contre l'implémentation de référence `qrcode-generator`
 * (parité totale à masque forcé sur v1→v10) ET décodée par le lecteur
 * indépendant `jsQR`. Toute dérive de pipeline (Reed-Solomon,
 * entrelacement, zigzag, BCH, séparateurs, capacités) casse un
 * hachage ci-dessous : le QR redeviendrait décoratif sans qu'aucun
 * test fonctionnel ne le voie.
 */
class QrCodeSvgConformiteTest extends TestCase
{
    /**
     * Matrices épinglées bit-à-bit (sortie auto : mireurs, timing,
     * alignement, RS, masque optimal, information de format).
     */
    public function test_fixtures_bit_a_bit_v1_v2_v4(): void
    {
        // v1-M (21×21) — « HELLO WORLD », scanné par téléphone au banc.
        $matrice = QrCodeSvg::creerMatrice('HELLO WORLD');
        $lignes = [
            '111111101100101111111',
            '100000100001001000001',
            '101110100101001011101',
            '101110101001001011101',
            '101110101110101011101',
            '100000101001001000001',
            '111111101010101111111',
            '000000001001100000000',
            '100010111111011111001',
            '000100001011100001111',
            '001111110011011010010',
            '111110001100010000000',
            '111110101010101100110',
            '000000001010111101011',
            '111111101110101011010',
            '100000100101110110011',
            '101110101101011000110',
            '101110100100100011011',
            '101110100111000111000',
            '100000100001010000000',
            '111111101111111110101',
        ];
        $this->assertSame($lignes, $this->enLignes($matrice));

        // v2-M (25×25) — mode octet UTF-8 multi-octets (é, Ω, à).
        $matrice = QrCodeSvg::creerMatrice('Vérification-Ω-éà-2026');
        $lignes = [
            '1111111011010111001111111',
            '1000001001100101101000001',
            '1011101011111001001011101',
            '1011101000111101001011101',
            '1011101001000010101011101',
            '1000001010010000101000001',
            '1111111010101010101111111',
            '0000000000110110000000000',
            '1010001101000100100100101',
            '0010010101011001011100101',
            '1101111000111010101010110',
            '0010000110110111100001011',
            '0011001010011010101100011',
            '0000010111100000101100000',
            '1100011100111110010001000',
            '0010110101011000110001001',
            '1101011100110010111111001',
            '0000000010010100100010110',
            '1111111010011100101010101',
            '1000001000010010100011001',
            '1011101001010011111111010',
            '1011101001010010110011010',
            '1011101010111010010011011',
            '1000001000100000111101000',
            '1111111010010010100101001',
        ];
        $this->assertSame($lignes, $this->enLignes($matrice));

        // v4-M (33×33) — URL de vérification d'attestation, cas d'usage
        // LOT 07 : bit-à-bit IDENTIQUE à qrcode-generator en sortie auto.
        $matrice = QrCodeSvg::creerMatrice('https://universite.test/verify/AB12CD34EF56GH78IJ90KL12MN34');
        $lignes = [
            '111111100101101011011011001111111',
            '100000100111100100001100001000001',
            '101110101111101010101100001011101',
            '101110101011100101100000101011101',
            '101110101110111110001110001011101',
            '100000101110000001101000001000001',
            '111111101010101010101010101111111',
            '000000001000101010010101000000000',
            '101111100101110001010010101111100',
            '110000001111010010011111001101111',
            '110001110100011110100010110010100',
            '111101000100110100001111000011101',
            '010010101110101001100000110011000',
            '001001000100100100001110000100111',
            '100111110011000100000000111011110',
            '100101001010011110100111011100100',
            '010110101100101101101010010111001',
            '110110010010110010111001011101100',
            '001011110101100101100100010110110',
            '000011000101101000111100110111110',
            '101101111011000110011111010011000',
            '100010001101000001110001100100101',
            '101100101001100111001100010100010',
            '100011001001110000000110010001111',
            '100011101000101111010010111110101',
            '000000001011110011010010100010100',
            '111111100111001110001101101010110',
            '100000101101001110001111100011111',
            '101110101010011110001110111111011',
            '101110101110001000110100010010111',
            '101110101100000101000111001101000',
            '100000100001010010110101010011100',
            '111111101100000001010010101100110',
        ];
        $this->assertSame($lignes, $this->enLignes($matrice));
    }

    /**
     * Hachages épinglés v1→v10 : toute régression d'un seul module sur
     * n'importe quelle version/niveau casse le hachage correspondant.
     */
    public static function hachagesFournisseur(): array
    {
        return [
            'v1-M 11 octets' => ['HELLO WORLD', 21, 'b2d499816b19de7f6dacc69052393ede91782a48dde0023aabfa693930b8e895'],
            'v3-M 32 octets' => ['QR-SEANCE-2026-10-25-REF-123456', 29, '8420ad630e928c25cee9c65de6d7f68c92518215c06ba0792524c85fbbb5d406'],
            'v6-M 100 octets' => [str_repeat('A', 100), 41, 'bcf4669a66753b0ee6ea08fcd314f337948c04e5155b5ca404895dafc468b8f4'],
            'v7-M 120 octets (info version + 6 alignements)' => [str_repeat('B', 120), 45, '81486865a36a2a13fadf6d99782f622a4e320f5daf484c0b862d4fdd3884eaec'],
            'v8-M 134 octets' => [str_repeat('C', 134), 49, 'c095b78629d4c076dafd0d3ad180a489b2bba960d3e2e64340c1a93d4f7832fe'],
            'v2-M UTF-8 26 octets' => ['Vérification-Ω-éà-2026', 25, '6f6f66473e2f33844ec306efb4f91660ba7688bd7a3e8500ae4245babe2d4769'],
            'v7-M 118 octets (6 alignements + info version)' => [str_repeat('J', 118), 45, 'ae31fc4d74291740a1859769d77089d49ca7fd0a69a9ffc5becf27f33ba5233b'],
            'v8-M 150 octets (blocs inégaux 38/39)' => [str_repeat('E', 150), 49, '8bf9ec422150a4038b94b38aa4961650e72155dff2bd9668f1988e0d701f9b0d'],
            'v9-M 169 octets (jeton émargement)' => [str_repeat('Z', 169), 53, '91af166730d408c869544f6ff6035414425a0343627b3831fa6d2b1b0d613643'],
            'v10-M 200 octets (compte 16 bits)' => [str_repeat('F', 200), 57, 'b098746de471eb6050b166a134900f16e5f578fc0b2f996477d314d20d92f2e4'],
            'v9-L 230 octets' => [str_repeat('G', 230), 53, 'de8e4601cfa6e863fbed33f90cf59a1445847f328543011589004688cc146d0d'],
            'v10-L 271 octets (plafond)' => [str_repeat('H', 271), 57, '93ba8ca46a56a914f7562d0a9911e8d85c39ae55fb00e15a08932771cfe6c0f2'],
            'v10-M 213 octets (bord M)' => [str_repeat('I', 213), 57, '0e15dd43a900f30db69496c687feda106e5a14d7fc5669f6f9d4a06097fca537'],
        ];
    }

    #[DataProvider('hachagesFournisseur')]
    public function test_hachage_matrice_v1_a_v10(string $contenu, int $taille, string $hachage): void
    {
        $lignes = $this->enLignes(QrCodeSvg::creerMatrice($contenu));

        $this->assertCount($taille, $lignes);
        $this->assertSame($hachage, hash('sha256', implode('|', $lignes)));
    }

    /**
     * Anti-dérive : tables de blocs et capacités cohérentes entre elles et
     * avec les totaux de codewords de la spec (26/44/…/346).
     */
    public function test_coherence_capacites_et_blocs(): void
    {
        $reflexion = new ReflectionClass(QrCodeSvg::class);
        $codewordsTotaux = [26, 44, 70, 100, 134, 172, 196, 242, 292, 346];

        foreach (['M' => 'BLOCS_M', 'L' => 'BLOCS_L'] as $niveau => $constante) {
            $groupes = $reflexion->getConstant($constante);
            $capacites = $reflexion->getConstant('CAPACITES_'.$niveau);

            $this->assertCount(10, $groupes, "$niveau : 10 versions attendues");
            $this->assertCount(10, $capacites, "$niveau : 10 capacités attendues");

            foreach ($groupes as $index => $groupesVersion) {
                $version = $index + 1;
                $donnees = 0;
                $correction = 0;
                foreach ($groupesVersion as [$blocs, $octetsDonnees, $octetsRs]) {
                    $donnees += $blocs * $octetsDonnees;
                    $correction += $blocs * $octetsRs;
                }

                $this->assertSame(
                    $codewordsTotaux[$index],
                    $donnees + $correction,
                    "v{$version}-{$niveau} : total codewords divergent de la spec"
                );
                $this->assertSame(
                    $donnees - ($version <= 9 ? 2 : 3),
                    $capacites[$index],
                    "v{$version}-{$niveau} : capacité incohérente avec les blocs"
                );
            }
        }
    }

    /** Patrons fonctionnels de spec : mireurs, module sombre, double info de format. */
    public function test_structure_fonctionnelle_spec(): void
    {
        $matrice = QrCodeSvg::creerMatrice('HELLO WORLD');
        $this->assertCount(21, $matrice);

        // Mireur haut-gauche 7×7 : bord + centre sombres, anneaux clairs.
        $mireur = [
            '1111111', '1000001', '1011101', '1011101', '1011101', '1000001', '1111111',
        ];
        for ($i = 0; $i < 7; $i++) {
            $this->assertSame($mireur[$i], substr($this->enLignes([$matrice[$i]])[0], 0, 7));
        }

        // Module sombre permanent : (ligne 4v+9, colonne 8) — v1 => (13, 8).
        $this->assertTrue($matrice[13][8]);

        // Les deux copies d'information de format portent les mêmes 15 bits.
        $copie1 = '';
        for ($i = 0; $i <= 5; $i++) {
            $copie1 .= $matrice[$i][8] ? '1' : '0';
        }
        $copie1 .= ($matrice[7][8] ? '1' : '0').($matrice[8][8] ? '1' : '0').($matrice[8][7] ? '1' : '0');
        for ($i = 9; $i < 15; $i++) {
            $copie1 .= $matrice[8][14 - $i] ? '1' : '0';
        }
        $copie2 = '';
        for ($i = 0; $i < 8; $i++) {
            $copie2 .= $matrice[8][20 - $i] ? '1' : '0';
        }
        for ($i = 8; $i < 15; $i++) {
            $copie2 .= $matrice[6 + $i][8] ? '1' : '0';
        }
        $this->assertSame($copie1, $copie2);

        // v7+ : information de version en deux copies identiques (BCH 18,6).
        $v7 = QrCodeSvg::creerMatrice(str_repeat('J', 118));
        $this->assertCount(45, $v7);
        $version1 = '';
        for ($i = 0; $i < 18; $i++) {
            $version1 .= $v7[(int) ($i / 3)][$i % 3 + 34] ? '1' : '0';
        }
        $version2 = '';
        for ($i = 0; $i < 18; $i++) {
            $version2 .= $v7[$i % 3 + 34][(int) ($i / 3)] ? '1' : '0';
        }
        $this->assertSame($version1, $version2);
        // Chaîne LSB-first : les 6 bits de version (poids 12-17) se lisent inversés.
        $this->assertSame(7, (int) bindec(strrev(substr($version1, 12))), 'Les 6 bits de poids fort encodent la version');
    }

    public function test_determinisme_et_exceptions_aux_limites(): void
    {
        $premier = QrCodeSvg::generer('https://universite.test/verify/X1', 64);
        $second = QrCodeSvg::generer('https://universite.test/verify/X1', 64);
        $this->assertSame($premier, $second);

        try {
            QrCodeSvg::generer('');
            $this->fail('Contenu vide : exception attendue.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('contenu vide', $exception->getMessage());
        }

        try {
            QrCodeSvg::generer(str_repeat('A', 272));
            $this->fail('272 octets : exception attendue (plafond v10-L = 271).');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('contenu trop long', $exception->getMessage());
        }
    }

    /**
     * @param  array<int, array<int, bool>>  $matrice
     * @return array<int, string>
     */
    private function enLignes(array $matrice): array
    {
        $lignes = [];
        foreach ($matrice as $ligne) {
            $lignes[] = implode('', array_map(fn (bool $module): string => $module ? '1' : '0', $ligne));
        }

        return $lignes;
    }
}
