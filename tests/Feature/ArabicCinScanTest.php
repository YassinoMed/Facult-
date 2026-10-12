<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Services\Identity\ArabicNormalizer;
use App\Services\Identity\CinParser;
use App\Services\Identity\OcrEngineInterface;
use Database\Seeders\LieuNaissanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Parcours HTTP complet de la CIN tunisienne ARABE (§1-28) :
 * OCR arabe → extraction → normalisation arabe → translittération →
 * pré-remplissage. La comparaison OCR arabe ↔ BD latine passe par la
 * translittération (§15) ; la validation humaine reste obligatoire ;
 * aucune donnée sensible (password/hash) n'est jamais exposée.
 *
 * Le moteur OCR factice est branché sur l'INTERFACE (§1) — exactement
 * comme un vrai moteur arabe le serait via la configuration.
 */
class ArabicCinScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(LieuNaissanceSeeder::class);
        $this->brancheMoteurOcr(self::texteCinArabe());
    }

    /**
     * Exemple §3 : libellés arabes, valeurs sur lignes séparées.
     */
    public static function texteCinArabe(): string
    {
        return "الجمهورية التونسية\n"
            ."بطاقة التعريف الوطنية\n"
            ."اللقب\n"
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

    private function brancheMoteurOcr(string $texte): void
    {
        $this->app->instance(OcrEngineInterface::class, new class($texte) implements OcrEngineInterface
        {
            public function __construct(private readonly string $texte) {}

            public function rawText(string $filePath, string $langue = OcrEngineInterface::LANGUE_ARABE_LATIN, ?int $psm = null): string
            {
                return $this->texte;
            }
        });
    }

    private function scan(?UploadedFile $fichier = null)
    {
        return $this->post(route('inscription.identity.scan'), [
            'document_type' => 'cin',
            'document' => $fichier ?? UploadedFile::fake()->image('cin.png', 300, 190),
        ], ['Accept' => 'application/json']);
    }

    private function creerEtudiantDossier(array $attributs = []): Etudiant
    {
        $faculte = Faculte::create([
            'nom' => 'Faculté Test',
            'code' => 'FT'.random_int(100, 999),
            'description' => 'Test',
            'active' => true,
        ]);

        // `statut` n'est plus mass-assignable (audit §P3-17).
        $statut = $attributs['statut'] ?? 'valide';
        unset($attributs['statut']);

        $etudiant = Etudiant::create(array_merge([
            'cin' => '12345678',
            'nom' => 'BEN ALI',
            'prenom' => 'MOHAMED',
            'email' => uniqid('arabe.', true).'@example.com',
            'date_naissance' => '2002-02-15',
            'sexe' => 'M',
            'faculte_id' => $faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Rue du Test',
            'ville' => 'Sfax',
            'code_postal' => '30000',
            'annee_bac' => '2020',
            'password' => 'MotDePasseArabe123',
        ], $attributs));
        $etudiant->definirStatut($statut);

        return $etudiant;
    }

    /* ── Parcours complet (§28) ─────────────────────────────────── */

    public function test_scan_cin_arabe_renvoie_translitteration_et_originaux(): void
    {
        $reponse = $this->scan();

        $reponse->assertOk()->assertJsonPath('success', true);

        // Langue détectée : arabe (§2)
        $reponse->assertJsonPath('language', ArabicNormalizer::LANGUE_ARABE);

        // §11 : le FORMULAIRE français reçoit la translittération
        $reponse->assertJsonPath('data.cin', '12345678')
            ->assertJsonPath('data.nom', 'BEN ALI')
            ->assertJsonPath('data.prenom', 'MOHAMED')
            ->assertJsonPath('data.lieu_naissance', 'SFAX')
            ->assertJsonPath('data.sexe', 'M')
            ->assertJsonPath('data.date_naissance', '2002-02-15');

        // §9/§12 : l'original arabe est conservé POUR COMPARAISON
        $reponse->assertJsonPath('originals.nom.raw', 'بن علي')
            ->assertJsonPath('originals.nom.transliterated', 'Ben Ali')
            ->assertJsonPath('originals.prenom.raw', 'محمد')
            ->assertJsonPath('originals.prenom.transliterated', 'Mohamed')
            ->assertJsonPath('originals.lieu_naissance.raw', 'صفاقس')
            ->assertJsonPath('originals.lieu_naissance.transliterated', 'Sfax');

        // Confiance portée sur les clés du formulaire
        $reponse->assertJsonPath('confidence.cin', 0.95);

        // Le CIN n'est JAMAIS translittéré (§5) — chiffres purs
        $this->assertSame('12345678', $reponse->json('data.cin'));
    }

    public function test_lieu_inconnu_de_la_table_est_translittere_en_repli(): void
    {
        $this->brancheMoteurOcr("اللقب\nبن يوسف\nمكان الولادة\nقرية الشمال\n");

        $reponse = $this->scan();

        $reponse->assertOk();

        // Pas dans la table administrative → repli translittération
        $lieu = $reponse->json('data.lieu_naissance');
        $this->assertNotNull($lieu);
        $this->assertMatchesRegularExpression('/^[A-Z]/', $lieu);
        $this->assertSame('قرية الشمال', $reponse->json('originals.lieu_naissance.raw'));
    }

    public function test_chiffres_arabo_indiques_dans_la_cin_et_la_date(): void
    {
        // §5-6 : ١٢٣٤٥٦٧٨ → 12345678 et ١٥/٠٢/٢٠٠٢ → 2002-02-15
        $this->brancheMoteurOcr(
            "اللقب\nبن علي\n"
            ."تاريخ الولادة\n١٥/٠٢/٢٠٠٢\n"
            ."رقم بطاقة التعريف الوطنية\n١٢٣٤٥٦٧٨\n",
        );

        $reponse = $this->scan();

        $reponse->assertOk()
            ->assertJsonPath('data.cin', '12345678')
            ->assertJsonPath('data.date_naissance', '2002-02-15');
    }

    /* ── Comparaison OCR arabe ↔ BD latine (§15-16, §19-20) ────── */

    public function test_ocr_arabe_correspond_au_dossier_latin_sans_divergence(): void
    {
        // §15 : OCR بن علي/محمد vs BD Ben Ali/Mohamed → correspondance
        // APRÈS translittération → pré-remplissage depuis la BD,
        // AUCUNE divergence signalée (ce n'est pas une erreur).
        $etudiant = $this->creerEtudiantDossier();

        $reponse = $this->scan();

        $reponse->assertOk()
            ->assertJsonPath('existing_student', true)
            ->assertJsonPath('source', 'dossier_existant')
            ->assertJsonPath('etudiant.cin', '12345678')
            ->assertJsonPath('etudiant.nom', 'BEN ALI');

        $this->assertArrayNotHasKey('divergences', $reponse->json(),
            'L\'OCR arabe بن علي ≡ BEN ALI après translittération : aucune divergence attendue.');

        // §20 : la donnée BD validée fait foi pour le formulaire
        $this->assertSame('BEN ALI', $reponse->json('etudiant.nom'));
    }

    public function test_ecart_reel_entre_ocr_arabe_et_bd_est_signale(): void
    {
        // OCR بن علي vs BD TRABELSI : identités différentes → divergence
        $this->creerEtudiantDossier(['nom' => 'TRABELSI']);

        $reponse = $this->scan();

        $reponse->assertOk()->assertJsonPath('existing_student', true);

        $divergences = collect($reponse->json('divergences'));
        $divergenceNom = $divergences->firstWhere('champ', 'nom');

        $this->assertNotNull($divergenceNom, 'La divergence de nom doit être signalée.');
        $this->assertSame('BEN ALI', $divergenceNom['extrait']);
        $this->assertSame('TRABELSI', $divergenceNom['enregistre']);
        $this->assertSame('different', $divergenceNom['verdict']);

        // La donnée BD n'est JAMAIS écrasée silencieusement (§20)
        $this->assertSame('TRABELSI', $reponse->json('etudiant.nom'));
    }

    public function test_le_scan_ne_cree_rien_et_ne_modifie_rien(): void
    {
        $this->creerEtudiantDossier();

        $this->scan();

        $this->assertSame(1, Etudiant::count());
        $this->assertSame([], Storage::disk('local')->allFiles(),
            'Aucune pièce d\'identité ne doit survivre au scan (§13 option A).');
    }

    /* ── Sécurité (§25, §27) ───────────────────────────────────── */

    public function test_la_reponse_ne_contient_jamais_password_ni_hash(): void
    {
        $etudiant = $this->creerEtudiantDossier();

        $corps = $this->scan()->getContent();

        $this->assertStringNotContainsString('password', $corps);
        $this->assertStringNotContainsString('$2y$', $corps);
        $this->assertStringNotContainsString('MotDePasseArabe123', $corps);
        $this->assertStringNotContainsString((string) $etudiant->id, 'Aucun identifiant interne exposé.');
    }

    public function test_charge_xss_arabe_ne_sort_jamais_comme_html(): void
    {
        // §25/§27 : le contenu OCR est NON FIABLE — la réponse est du
        // JSON strict (échappé), et la normalisation retire la
        // ponctuation de script du champ nom.
        $this->brancheMoteurOcr("اللقب\nبن علي <script>alert('xss')</script>\nالاسم\nمحمد\n");

        $reponse = $this->scan();

        $reponse->assertOk();

        $this->assertSame('application/json', $reponse->headers->get('Content-Type'));
        $this->assertStringNotContainsString('<script>', $reponse->getContent(),
            'Le JSON doit échapper toute charge malveillante.');
    }

    public function test_injection_sql_via_champ_ocr_est_neutralisee(): void
    {
        // §21/§25/§27 : l'ambiguïté OCR est CONSERVÉE (jamais nettoyée en
        // identifiant « valide ») — la requête reste 100 % paramétrée
        // (Eloquent), la validation signale l'ambiguïté et la vérification
        // humaine est exigée. Aucun SQL concaténé, aucune donnée créée.
        $this->brancheMoteurOcr(
            "رقم بطاقة التعريف\n"
            ."12345678' OR '1'='1\n"
            ."اللقب\nبن علي\n"
            ."تاريخ الولادة\n15/02/2002\n",
        );

        $reponse = $this->scan();

        $reponse->assertOk();

        // L'identifiant extrait N'EST PAS une CIN valide : l'ambiguïté est
        // signalée, jamais transformée en valeur de confiance.
        $cin = $reponse->json('data.cin');
        $this->assertFalse(CinParser::validerNumero($cin));

        // Confiance insuffisante → alerte de vérification manuelle.
        $alertes = $reponse->json('alerts') ?? [];
        $this->assertNotEmpty($alertes);
        $this->assertStringContainsString(
            'vérification manuelle',
            implode(' ', array_map('strval', $alertes))
        );

        // Aucune énumération possible : existing_student reste faux et
        // aucune ligne n'est créée par la charge malveillante.
        $this->assertFalse($reponse->json('existing_student'));
        $this->assertSame(0, Etudiant::count());
    }

    /* ── Base de données utf8mb4 (§22) ─────────────────────────── */

    public function test_les_donnees_arabes_persisteraient_sans_corruption(): void
    {
        // §22 : la colonne nom (utf8mb4) accepte l'arabe sans corruption
        $etudiant = $this->creerEtudiantDossier(['nom' => 'بن علي']);

        $relu = Etudiant::where('cin', '12345678')->first();

        $this->assertSame('بن علي', $relu->nom);
        $this->assertSame($etudiant->id, $relu->id);
    }

    public function test_recherche_multilingue_par_translitteration(): void
    {
        // §23 : la comparaison normalisée permet de retrouver le même
        // étudiant depuis la forme arabe OU latine — ici via le matcher
        // du scan (le registre garde la recherche latine existante).
        $this->creerEtudiantDossier();

        $this->brancheMoteurOcr(self::texteCinArabe());
        $reponse = $this->scan();

        $reponse->assertJsonPath('existing_student', true);

        // Et un étudiant au nom différent n'est PAS confondu
        $this->brancheMoteurOcr("اللقب\nالطرابلسي\nالاسم\nمحمد\nتاريخ الولادة\n15/02/2002\nرقم بطاقة التعريف\n12345678\n");
        $autre = $this->scan();
        $autre->assertJsonPath('existing_student', true);
        $this->assertNotEmpty($autre->json('divergences'));
    }
}
