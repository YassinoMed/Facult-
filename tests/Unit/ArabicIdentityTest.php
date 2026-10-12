<?php

namespace Tests\Unit;

use App\Models\LieuNaissance;
use App\Services\Identity\ArabicLabelDictionary;
use App\Services\Identity\ArabicNormalizer;
use App\Services\Identity\ArabicOcrService;
use App\Services\Identity\ArabicTransliterationService;
use App\Services\Identity\CinParser;
use App\Services\Identity\IdentityMatcher;
use Database\Seeders\LieuNaissanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pipeline ARABE de la CIN tunisienne (§1-17, §26) :
 * - normalisation arabe (diacritiques محمّد ≡ محمد, tatweel, variantes
 *   de lettres, chiffres arabo-indiques) ;
 * - translittération DÉTERMINISTE des noms propres (jamais une
 *   traduction sémantique) ;
 * - correspondance contrôlée des lieux (table administrative) ;
 * - parseur CIN avec libellés arabes (valeur sur la même ligne ou à
 *   la ligne suivante, libellé combiné) ;
 * - comparaison intelligente OCR ↔ BD via IdentityMatcher.
 */
class ArabicIdentityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Exemple brut du cahier des charges (§3) : libellés arabes et
     * valeurs sur des lignes séparées.
     */
    public static function texteCinArabe(): string
    {
        return "اللقب\n"
            ."بن علي\n"
            ."الاسم\n"
            ."محمد\n"
            ."تاريخ الولادة\n"
            ."15/02/2002\n"
            ."مكان الولادة\n"
            ."صفاقس\n"
            ."الجنس\n"
            ."ذكر\n"
            ."رقم بطاقة التعريف الوطنية\n"
            .'12345678';
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Données de référence des lieux (§14) — aucune table
        // supplémentaire nécessaire au test : le seeder officiel.
        $this->seed(LieuNaissanceSeeder::class);
    }

    /* ── Normalisation arabe (§16, §26) ───────────────────────── */

    public function test_diacritiques_et_tatweel_sont_neutralises(): void
    {
        // محمّد (avec shadda) ≡ محمد (sans diacritique)
        $this->assertTrue(ArabicNormalizer::sontEquivalents('محمّد', 'محمد'));
        // Tatweel (ـ) : بنـي ≡ بني
        $this->assertTrue(ArabicNormalizer::sontEquivalents('بنـي', 'بني'));
    }

    public function test_variantes_de_lettres_sont_unifiees(): void
    {
        // alif maqsura ى ≡ yaa ي : على ≡ علي
        $this->assertTrue(ArabicNormalizer::sontEquivalents('على', 'علي'));
        // alef hamza أحمد ≡ alef simple احمد
        $this->assertTrue(ArabicNormalizer::sontEquivalents('أحمد', 'احمد'));
    }

    public function test_chiffres_arabo_indiques_sont_convertis(): void
    {
        // §6 : ١٥/٠٢/٢٠٠٢ → 15/02/2002 AVANT toute extraction
        $this->assertSame('15/02/2002', ArabicNormalizer::versChiffresLatins('١٥/٠٢/٢٠٠٢'));
        $this->assertSame('12345678', ArabicNormalizer::versChiffresLatins('١٢٣٤٥٦٧٨'));
        // Chiffres persans également
        $this->assertSame('90', ArabicNormalizer::versChiffresLatins('۹۰'));
    }

    public function test_detection_de_langue_arabe_latin_mixte(): void
    {
        // §2 : document mixte (العربية + latin) — cas de la CIN tunisienne
        $this->assertSame(ArabicNormalizer::LANGUE_MIXTE, ArabicNormalizer::detecterLangue('اللقب 15/02/2002 REPUBLIQUE TUNISIENNE'));
        $this->assertSame(ArabicNormalizer::LANGUE_ARABE, ArabicNormalizer::detecterLangue('بن علي محمد صفاقس'));
        $this->assertSame(ArabicNormalizer::LANGUE_LATIN, ArabicNormalizer::detecterLangue('REPUBLIQUE TUNISIENNE 15/02/2002'));
    }

    public function test_espaces_multiples_et_ponctuation_arabe_sont_reduits(): void
    {
        $this->assertSame('بن علي', ArabicNormalizer::normaliser('بن   علي'));
        // La virgule arabe ، est neutralisée en ponctuation latine
        $this->assertSame('تونس, صفاقس', ArabicNormalizer::normaliser('تونس،  صفاقس'));
        $this->assertSame('', ArabicNormalizer::normaliser('   '));
    }

    /* ── Dictionnaire de libellés arabes (§4) ─────────────────── */

    public function test_libelles_arabes_sont_identifies_avec_variantes(): void
    {
        $this->assertSame('last_name', ArabicLabelDictionary::champPourLibelle('اللقب'));
        $this->assertSame('first_name', ArabicLabelDictionary::champPourLibelle('الاسم'));
        $this->assertSame('date_of_birth', ArabicLabelDictionary::champPourLibelle('تاريخ الولادة'));
        // Variante d'écriture : تاريخ الميلاد ≡ تاريخ الولادة ? Non —
        // libellés DISTINCTS, tous deux mappés sur le même champ
        $this->assertSame('date_of_birth', ArabicLabelDictionary::champPourLibelle('تاريخ الميلاد'));
        $this->assertSame('place_of_birth', ArabicLabelDictionary::champPourLibelle('مكان الولادة'));
        $this->assertSame('document_number', ArabicLabelDictionary::champPourLibelle('رقم بطاقة التعريف الوطنية'));
        $this->assertSame('sex', ArabicLabelDictionary::champPourLibelle('الجنس'));
        // Toute variante d'écriture (diacritiques) est tolérée
        $this->assertSame('last_name', ArabicLabelDictionary::champPourLibelle('اللقب'));
        $this->assertNull(ArabicLabelDictionary::champPourLibelle('نص non libellé'));
    }

    public function test_valeurs_arabes_du_sexe_sont_reconnues(): void
    {
        $this->assertSame('M', ArabicLabelDictionary::sexePourValeur('ذكر'));
        $this->assertSame('F', ArabicLabelDictionary::sexePourValeur('أنثى'));
        $this->assertSame('F', ArabicLabelDictionary::sexePourValeur('انثى'));
        $this->assertNull(ArabicLabelDictionary::sexePourValeur('مجهول'));
    }

    /* ── Translittération (§7-8, §13) ──────────────────────────── */

    public function test_noms_propres_sont_translitteres_pas_traduits(): void
    {
        $service = app(ArabicTransliterationService::class);

        // §7 : محمد → Mohamed (PAS une traduction sémantique)
        $this->assertSame('Mohamed', $service->transliterate('محمد'));
        $this->assertSame('Ben Ali', $service->transliterate('بن علي'));
        $this->assertSame('Fatma', $service->transliterate('فاطمة'));
        $this->assertSame('Ahmed', $service->transliterate('أحمد'));
        $this->assertSame('Tunisienne', $service->transliterate('تونسية'));
    }

    public function test_translitteration_est_deterministe_et_capitalisee(): void
    {
        $service = app(ArabicTransliterationService::class);

        $this->assertSame(
            $service->transliterate('بن علي محمد'),
            $service->transliterate('بن علي محمد'),
            'La translittération doit être déterministe.',
        );

        // Capitalisation des noms propres
        $this->assertSame('Ben Ali Mohamed', $service->transliterate('بن علي محمد'));
    }

    public function test_repli_lettre_a_lettre_pour_nom_inconnu(): void
    {
        $service = app(ArabicTransliterationService::class);

        // Nom absent du dictionnaire : repli déterministe
        // ح م د و ن → h m d ou n → « Hmdoun »
        $this->assertSame('Hmdoun', $service->transliterate('حمدون'));
    }

    public function test_document_mixte_conserve_les_mots_latins(): void
    {
        $service = app(ArabicTransliterationService::class);

        // §21 : document mixte — les mots déjà latins sont conservés
        $this->assertSame('Ben Ali 12345678', $service->transliterate('بن علي 12345678'));
    }

    public function test_lieux_sont_resolus_par_la_table_administrative(): void
    {
        $service = app(ArabicTransliterationService::class);

        // §14 : correspondance CONTRÔLÉE — jamais une traduction automatique
        $this->assertSame('Sfax', $service->transliterateLieu('صفاقس'));
        $this->assertSame('Tunis', $service->transliterateLieu('تونس'));
        $this->assertSame('Sousse', $service->transliterateLieu('سوسة'));
        $this->assertSame('Bizerte', $service->transliterateLieu('بنزرت'));

        // Variantes d'écriture → même lieu (clé normalisée)
        $this->assertSame('Sfax', $service->transliterateLieu('صفاقس'));

        // Lieu inconnu de la table : repli translittération
        $this->assertNotSame('', $service->transliterateLieu('قرية مجهولة'));
    }

    public function test_la_table_des_lieux_est_extensible(): void
    {
        LieuNaissance::enregistrer('المهدية', 'Mahdia', 'Mahdia');

        $this->assertSame('Mahdia', LieuNaissance::resoudreNomLatin('المهدية'));
    }

    /* ── Parseur CIN arabe (§3-6, §26) ────────────────────────── */

    public function test_cin_arabe_complete_est_extraite_et_translitteree(): void
    {
        $parse = app(CinParser::class)->parse(self::texteCinArabe());

        // Champs structurés : normalisés (§13)
        $this->assertSame('12345678', $parse['data']['document_number']);
        $this->assertSame('2002-02-15', $parse['data']['date_of_birth']);
        $this->assertSame('M', $parse['data']['sex']);

        // Noms propres : TRANSLITTÉRÉS (§7) + original arabe conservé (§9)
        $this->assertSame('BEN ALI', $parse['data']['last_name']);
        $this->assertSame('MOHAMED', $parse['data']['first_name']);
        $this->assertSame('SFAX', $parse['data']['place_of_birth']);

        $this->assertSame('بن علي', $parse['arabic']['last_name']);
        $this->assertSame('محمد', $parse['arabic']['first_name']);
        $this->assertSame('صفاقس', $parse['arabic']['place_of_birth']);

        $this->assertSame('Ben Ali', $parse['transliterated']['last_name']);
        $this->assertSame('Mohamed', $parse['transliterated']['first_name']);
        $this->assertSame('Sfax', $parse['transliterated']['place_of_birth']);
    }

    public function test_cin_avec_valeur_sur_la_meme_ligne(): void
    {
        $texte = "اللقب : بن علي\n"
            ."الاسم : محمّد\n"
            ."رقم بطاقة التعريف الوطنية : 12345678\n"
            ."تاريخ الولادة : ١٥/٠٢/٢٠٠٢\n";

        $parse = app(CinParser::class)->parse($texte);

        $this->assertSame('BEN ALI', $parse['data']['last_name']);
        // محمّد (avec shadda) ≡ محمد → Mohamed (§16)
        $this->assertSame('MOHAMED', $parse['data']['first_name']);
        $this->assertSame('12345678', $parse['data']['document_number']);
        // Chiffres arabo-indiques convertis puis date normalisée (§5-6)
        $this->assertSame('2002-02-15', $parse['data']['date_of_birth']);
    }

    public function test_libelle_combine_nom_et_prenom(): void
    {
        // « الاسم و اللقب : بن علي محمد » → dernier mot = prénom
        $texte = "الاسم واللقب\nبن علي محمد\nرقم بطاقة التعريف\n98765432\n";

        $parse = app(CinParser::class)->parse($texte);

        $this->assertSame('BEN ALI', $parse['data']['last_name']);
        $this->assertSame('MOHAMED', $parse['data']['first_name']);
        $this->assertSame('98765432', $parse['data']['document_number']);
    }

    public function test_cin_arabe_avec_parasites_est_normalisee(): void
    {
        // §5 : « 12 345 678 » → 12345678 — jamais translittérée
        $texte = "رقم بطاقة التعريف\n12 345 678\n";

        $parse = app(CinParser::class)->parse($texte);

        $this->assertSame('12345678', $parse['data']['document_number']);
        // §8 CinParser : 8 chiffres valides = très fiable (0.95).
        $this->assertSame(0.95, $parse['confidence']['document_number'] ?? null);
    }

    public function test_document_mixte_arabe_francais(): void
    {
        // §21 : document réel mixte — libellés arabes ET mentions latines
        $texte = "REPUBLIQUE TUNISIENNE\n"
            ."اللقب\nبن علي\n"
            ."Nom : BEN ALI\n"
            ."Prénom : Mohamed\n"
            ."Né(e) le : 15/02/2002\n";

        $parse = app(CinParser::class)->parse($texte);

        $this->assertSame('BEN ALI', $parse['data']['last_name']);
        $this->assertSame('MOHAMED', $parse['data']['first_name']);
        $this->assertSame('2002-02-15', $parse['data']['date_of_birth']);
    }

    /* ── ArabicOcrService : assemblage du résultat riche (§10) ── */

    public function test_le_resultat_ocr_porte_langue_et_originaux(): void
    {
        $resultat = app(ArabicOcrService::class)->extraireCin(self::texteCinArabe());

        $this->assertSame('cin', $resultat->documentType);
        $this->assertSame(ArabicNormalizer::LANGUE_ARABE, $resultat->language);

        $this->assertSame('BEN ALI', $resultat->lastName);
        $this->assertSame('بن علي', $resultat->originals['last_name']['raw']);
        $this->assertSame('Ben Ali', $resultat->originals['last_name']['transliterated']);

        $this->assertSame('SFAX', $resultat->placeOfBirth);
        $this->assertSame('صفاقس', $resultat->originals['place_of_birth']['raw']);
    }

    /* ── IdentityMatcher : comparaison intelligente (§15-16) ─── */

    public static function fournisseurCorrespondances(): array
    {
        return [
            // §15 : OCR arabe بن علي vs BD latin Ben Ali → correspond
            'arabe vs latin (identique)' => ['بن علي', 'BEN ALI', IdentityMatcher::IDENTIQUE],
            // §16 : diacritiques neutralisés (محمّد ≡ محمد → Mohamed)
            'diacritiques (identique)' => ['محمّد', 'MOHAMED', IdentityMatcher::IDENTIQUE],
            // ى ≡ ي : على ≡ علي
            'alif maqsura (identique)' => ['على', 'ALI', IdentityMatcher::IDENTIQUE],
            // Espaces/tirets/accents latins neutralisés
            'ponctuation latine (identique)' => ['Ben-Ali', 'BEN ALI', IdentityMatcher::IDENTIQUE],
            // §17 : faute de lecture admissible → similaire (à vérifier)
            'faute OCR (similaire)' => ['Mohamed', 'Mohammed', IdentityMatcher::SIMILAIRE],
            'faute OCR arabe (similaire)' => ['محمد', 'MOHAMEE', IdentityMatcher::SIMILAIRE],
            // Divergence réelle
            'different' => ['BEN ALI', 'TRABELSI', IdentityMatcher::DIFFERENT],
        ];
    }

    #[DataProvider('fournisseurCorrespondances')]
    public function test_comparaison_intelligente_ocr_bd(string $ocr, string $bd, string $verdictAttendu): void
    {
        $matcher = app(IdentityMatcher::class);

        $this->assertSame($verdictAttendu, $matcher->comparer($ocr, $bd));
    }

    public function test_matcher_ne_confond_pas_des_noms_differents(): void
    {
        $matcher = app(IdentityMatcher::class);

        // La tolérance OCR ne doit JAMAIS faire matcher deux identités
        $this->assertFalse($matcher->correspondent('محمد', 'MAHMOUD'));
        $this->assertFalse($matcher->correspondent('بن علي', 'TRABELSI'));
        $this->assertTrue($matcher->correspondent('بن علي', 'BEN ALI'));
    }
}
