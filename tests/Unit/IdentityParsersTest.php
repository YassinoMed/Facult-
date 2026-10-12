<?php

namespace Tests\Unit;

use App\Services\Identity\CinParser;
use App\Services\Identity\IdentityDataNormalizer;
use App\Services\Identity\MrzParser;
use App\Services\Identity\PassportParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tests unitaires des parseurs d'identité (§6-7, §21) :
 * - MRZ TD3 : checksums ICAO valides/invalides, champs, dates ;
 * - CIN : normalisation (espaces, parasites, ambiguïtés) ;
 * - passeport : priorité MRZ, repli libellés, confiance.
 *
 * MRZ de référence (checksums recalculés avec l'algorithme 7-3-1) :
 *   P<TUNBENALI<<MOHAMED<<<<<<<<<<<<<<<<<<<<<<<<
 *   AB1234567 1 TUN 820215 4 M 300101 9 <<<<<<<<<<<<<<< 0 2
 */
class IdentityParsersTest extends TestCase
{
    /**
     * MRZ TD3 de référence — checksums recalculés (algorithme 7-3-1) :
     *   Ligne 1 : P<TUN + BENALI<<MOHAMED + padding (44)
     *   Ligne 2 : AB1234567·1 · TUN · 820215·4 · M · 300101·9 · n° perso ·0 · composite·2
     * Construites via str_repeat : aucun comptage manuel sujet à erreur.
     */
    public static function mrzValide(): string
    {
        return 'P<TUNBENALI<<MOHAMED'.str_repeat('<', 24)."\n"
            .'AB12345671TUN8202154M3001019'.str_repeat('<', 14).'02';
    }

    /** MRZ volontairement corrompue : check digit du n° de document faux (9 ≠ 1). */
    public static function mrzDocCorrompu(): string
    {
        return 'P<TUNBENALI<<MOHAMED'.str_repeat('<', 24)."\n"
            .'AB12345679TUN8202154M3001019'.str_repeat('<', 14).'02';
    }

    /** MRZ expirée (2005) avec checksums cohérents (expiry 050101 → check 3). */
    public static function mrzExpiree(): string
    {
        return 'P<TUNBENALI<<MOHAMED'.str_repeat('<', 24)."\n"
            .'AB12345671TUN8202154M0501013'.str_repeat('<', 14).'04';
    }

    public const TEXTE_CIN = "REPUBLIQUE TUNISIENNE\n"
        ."CARTE D'IDENTITE NATIONALE\n"
        ."CIN : 12345678\n"
        ."Nom : BEN ALI\n"
        ."Prénom : Mohamed\n"
        ."Né(e) le : 15/02/2002\n"
        ."Lieu de naissance : Sfax\n"
        .'Sexe : Masculin';

    /* ── MRZ / checksums (§7, §16) ────────────────────────────── */

    public function test_mrz_valide_est_parsee_avec_checksums_ok(): void
    {
        $resultat = MrzParser::parse(self::mrzValide());

        $this->assertNotNull($resultat);
        $this->assertTrue($resultat['valide'], 'Tous les checksums doivent être valides.');

        $champs = $resultat['champs'];
        $this->assertSame('AB1234567', $champs['document_number']);
        $this->assertSame('BENALI', $champs['last_name']);
        $this->assertSame('MOHAMED', $champs['first_name']);
        $this->assertSame('TUN', $champs['nationality']);
        $this->assertSame('1982-02-15', $champs['date_of_birth']);
        $this->assertSame('M', $champs['sex']);
        $this->assertSame('2030-01-01', $champs['expiry_date']);
    }

    public function test_mrz_avec_checksum_invalide_est_signalee(): void
    {
        $resultat = MrzParser::parse(self::mrzDocCorrompu());

        $this->assertNotNull($resultat);
        $this->assertFalse($resultat['valide']);
        $this->assertNotNull($resultat['raison']);
        $this->assertFalse($resultat['champs']['checksums']['document_number']);
        $this->assertTrue($resultat['champs']['checksums']['date_of_birth']);
    }

    public function test_texte_sans_mrz_retourne_null(): void
    {
        $this->assertNull(MrzParser::parse(self::TEXTE_CIN));
        $this->assertNull(MrzParser::parse(''));
    }

    public function test_algorithme_chiffre_de_controle_icao(): void
    {
        // Valeurs de référence de la norme ICAO Doc 9303 (exemple TD3)
        $this->assertTrue(MrzParser::verifie('AB1234567', '1'));
        $this->assertTrue(MrzParser::verifie('820215', '4'));
        $this->assertTrue(MrzParser::verifie('300101', '9'));
        $this->assertFalse(MrzParser::verifie('820215', '5'));
        $this->assertFalse(MrzParser::verifie('820215', 'X'));
    }

    /* ── Parseur CIN (§6, §21) ────────────────────────────────── */

    public function test_cin_extraite_avec_confiance_elevee(): void
    {
        $parse = app(CinParser::class)->parse(self::TEXTE_CIN);

        $this->assertSame('12345678', $parse['data']['document_number']);
        $this->assertSame('BEN ALI', $parse['data']['last_name']);
        $this->assertSame('MOHAMED', $parse['data']['first_name']);
        $this->assertSame('2002-02-15', $parse['data']['date_of_birth']);
        $this->assertSame('SFAX', $parse['data']['place_of_birth']);
        $this->assertSame('M', $parse['data']['sex']);

        $this->assertSame(0.95, $parse['confidence']['document_number'] ?? null);
        $this->assertGreaterThanOrEqual(0.9, $parse['confidence']['date_of_birth'] ?? 0);
    }

    public function test_cin_bruitee_est_normalisee(): void
    {
        $texte = "CIN : 12 345-678.\nNom : BEN ALI\nPrénom : Mohamed";

        $parse = app(CinParser::class)->parse($texte);

        // Espaces et parasites supprimés, format CIN reconnu
        $this->assertSame('12345678', $parse['data']['document_number']);
        $this->assertSame(0.95, $parse['confidence']['document_number'] ?? null);
    }

    public function test_cin_avec_caractere_ambigu_exige_verification_humaine(): void
    {
        // §6 : « 1234567B » ne doit JAMAIS passer pour une CIN fiable
        $texte = "CIN : 1234567B\nNom : BEN ALI\nPrénom : Mohamed";

        $parse = app(CinParser::class)->parse($texte);

        // §8 : la valeur ambiguë « 1234567B » est conservée TELLE
        // QUELLE (jamais inventée/corrigée aveuglément), mais JAMAIS
        // validée — la confiance dégradée force la vérification humaine.
        $this->assertSame('1234567B', $parse['data']['document_number'] ?? null);
        $this->assertLessThan(
            (float) config('identity.confiance.moyen'),
            $parse['confidence']['document_number'] ?? 0,
            'Une CIN ambiguë doit avoir une confiance dégradée.',
        );
    }

    public function test_cin_avec_confusion_o_vers_zero_est_corrigee(): void
    {
        $texte = "CIN : 12O45678\nNom : BEN ALI\nPrénom : Mohamed";

        $parse = app(CinParser::class)->parse($texte);

        $this->assertSame('12045678', $parse['data']['document_number']);
        $this->assertSame(0.95, $parse['confidence']['document_number']);
    }

    public function test_donnees_cin_manquantes_ne_sont_pas_inventees(): void
    {
        $parse = app(CinParser::class)->parse("REPUBLIQUE TUNISIENNE\nCARTE D'IDENTITE");

        // Aucun champ inventé : les clés absentes restent absentes
        $this->assertSame([], $parse['data']);
        $this->assertSame([], $parse['confidence']);
    }

    /* ── Parseur passeport (§7, §21) ──────────────────────────── */

    public function test_passeport_mrz_prioritaire_confiance_elevee(): void
    {
        $parse = app(PassportParser::class)->parse(self::mrzValide());

        $this->assertSame('AB1234567', $parse['data']['document_number']);
        $this->assertSame('BENALI', $parse['data']['last_name']);
        $this->assertSame('MOHAMED', $parse['data']['first_name']);
        $this->assertSame('1982-02-15', $parse['data']['date_of_birth']);
        $this->assertSame('TUN', $parse['data']['nationality']);
        $this->assertTrue($parse['mrz_valide']);

        foreach ($parse['confidence'] as $niveau) {
            $this->assertSame(0.98, $niveau);
        }
    }

    public function test_passeport_mrz_invalide_degrade_la_confiance(): void
    {
        $parse = app(PassportParser::class)->parse(self::mrzDocCorrompu());

        $this->assertFalse($parse['mrz_valide']);
        $this->assertLessThan((float) config('identity.confiance.moyen'), $parse['confidence']['document_number']);
    }

    public function test_passeport_sans_mrz_repli_libelles_faible_confiance(): void
    {
        $texte = "PASSEPORT\nNom : BEN ALI\nPrénom : Mohamed\nNé le : 15/02/2002\nSexe : M";

        $parse = app(PassportParser::class)->parse($texte);

        $this->assertNull($parse['mrz_valide']);
        $this->assertSame('BEN ALI', $parse['data']['last_name']);
        $this->assertSame('2002-02-15', $parse['data']['date_of_birth']);
        $this->assertSame(0.60, $parse['confidence']['last_name']);
    }

    public function test_passeport_expire_degrade_la_confiance_dexpiration(): void
    {
        // Expiration 2005 : expirée → confiance dégradée (§16)
        $parse = app(PassportParser::class)->parse(self::mrzExpiree());

        $this->assertSame('2005-01-01', $parse['data']['expiry_date']);
        $this->assertLessThan(
            (float) config('identity.confiance.moyen'),
            $parse['confidence']['expiry_date'],
            'Un passeport expiré doit dégrader la confiance de la date d\'expiration.',
        );
    }

    /* ── Normalisation (§6) ───────────────────────────────────── */

    public static function fournisseurNormalisation(): array
    {
        return [
            'espaces et parasites' => [' 12 345-678. ', '12345678'],
            'confusions OCR' => ['12O45678', '12045678'],
            'nombres avec I/l' => ['I2345678', '12345678'],
        ];
    }

    #[DataProvider('fournisseurNormalisation')]
    public function test_identifiant_cin_est_normalise(string $brut, string $attendu): void
    {
        $normalise = app(IdentityDataNormalizer::class)->normaliserCin([
            'document_number' => $brut,
        ]);

        $this->assertSame($attendu, $normalise['document_number']);
    }

    public function test_date_future_comme_naissance_est_rejetee(): void
    {
        $normalise = app(IdentityDataNormalizer::class)->normaliserCin([
            'date_of_birth' => date('Y-m-d', strtotime('+1 year')),
        ]);

        $this->assertArrayNotHasKey('date_of_birth', $normalise);
    }

    public function test_sexe_invalide_est_ignored(): void
    {
        $normalise = app(IdentityDataNormalizer::class)->normaliserCin([
            'sex' => 'X',
        ]);

        $this->assertArrayNotHasKey('sex', $normalise);
    }
}
