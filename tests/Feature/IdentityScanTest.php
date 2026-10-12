<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Services\Identity\IdentityDocumentService;
use App\Services\Identity\OcrEngineInterface;
use App\Services\PeriodeInscriptionService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Parcours public de scan CIN / Passeport (§1-21) :
 * - upload : validation profonde (MIME réel, taille, extension,
 *   polyglot, corrompu) ;
 * - OCR : extraction CIN/passeport (moteur factice branché sur
 *   l'interface — l'architecture reste interchangeable §4) ;
 * - base de données : trouvé / introuvable / divergences ;
 * - sécurité : jamais password/hash, CSRF, rate limiting, suppression
 *   du fichier temporaire (§13 option A), path traversal, accès public.
 */
class IdentityScanTest extends TestCase
{
    use RefreshDatabase;

    public static function texteCin(): string
    {
        return "REPUBLIQUE TUNISIENNE\n"
            ."CIN : 12345678\n"
            ."Nom : BEN ALI\n"
            ."Prénom : Mohamed\n"
            ."Né(e) le : 15/02/2002\n"
            ."Lieu de naissance : Sfax\n"
            .'Sexe : Masculin';
    }

    public static function mrzPasseport(): string
    {
        return 'P<TUNBENALI<<MOHAMED'.str_repeat('<', 24)."\n"
            .'AB12345671TUN8202154M3001019'.str_repeat('<', 14).'02';
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->brancheMoteurOcr(self::texteCin());

        // Page publique en régime strict : période ouverte globale.
        $annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => true,
        ]);
        app(PeriodeInscriptionService::class)->creerNormale([
            'annee_universitaire_id' => $annee->id,
            'opened_at' => '2020-01-01 00:00', 'closed_at' => '2030-12-31 23:59',
        ], null);
    }

    /**
     * §4 — l'architecture OCR est interchangeable : le test branche un
     * moteur factice sur l'INTERFACE, exactement comme un vrai moteur
     * (tesseract, cloud) le serait en production via la configuration.
     */
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

    /** Crée un étudiant complet dont le dossier correspond au CIN scanné. */
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
            'prenom' => 'Mohamed',
            'email' => uniqid('scan.', true).'@example.com',
            'date_naissance' => '2002-02-15',
            'sexe' => 'M',
            'faculte_id' => $faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Rue du Test',
            'ville' => 'Sfax',
            'code_postal' => '30000',
            'annee_bac' => '2020',
            'password' => 'MotDePasseScan123',
        ], $attributs));
        $etudiant->definirStatut($statut);

        return $etudiant;
    }

    private function scan(UploadedFile $fichier, string $type = 'cin')
    {
        // Le frontend appelle l'endpoint via fetch avec Accept: JSON —
        // les erreurs de validation sont ainsi rendues en 422 JSON.
        return $this->post(route('inscription.identity.scan'), [
            'document_type' => $type,
            'document' => $fichier,
        ], ['Accept' => 'application/json']);
    }

    /* ── Upload : validation profonde (§14, §21) ───────────────── */

    public function test_scan_image_valide_extrait_la_cin(): void
    {
        $reponse = $this->scan(UploadedFile::fake()->image('cin.png', 200, 120));

        $reponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('document_type', 'cin')
            ->assertJsonPath('data.cin', '12345678')
            ->assertJsonPath('data.nom', 'BEN ALI')
            ->assertJsonPath('data.prenom', 'MOHAMED')
            ->assertJsonPath('data.date_naissance', '2002-02-15')
            ->assertJsonPath('data.lieu_naissance', 'SFAX')
            ->assertJsonPath('data.sexe', 'M')
            ->assertJsonPath('confidence.cin', 0.95)
            ->assertJsonPath('existing_student', false);
    }

    public function test_scan_pdf_valide_est_accepte(): void
    {
        $reponse = $this->scan(
            UploadedFile::fake()->createWithContent('cin.pdf', "%PDF-1.4\n%%EOF\n"),
        );

        $reponse->assertOk()->assertJsonPath('success', true);
    }

    public function test_fichier_trop_volumineux_est_rejetee(): void
    {
        config(['identity.upload.taille_max' => 1024]); // 1 Ko pour le test

        $reponse = $this->scan(UploadedFile::fake()->image('cin.png', 800, 600));

        $reponse->assertStatus(422);
        $this->assertSame([], Storage::disk('local')->allFiles(), 'Aucun fichier ne doit être conservé sur un rejet.');
    }

    public function test_mauvais_mime_reel_est_rejete(): void
    {
        // .png dont le CONTENU est du texte : MIME réel ≠ extension
        $reponse = $this->scan(UploadedFile::fake()->createWithContent('cin.png', 'du texte, pas une image'));

        $reponse->assertStatus(422);
    }

    public function test_extension_dangereuse_est_rejetee(): void
    {
        $reponse = $this->scan(UploadedFile::fake()->createWithContent('scan.php', '<?php echo "evil"; ?>'));

        $reponse->assertStatus(422);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_fichier_corrompu_est_rejete(): void
    {
        // En-tête PNG puis octets illisibles : getimagesize échouera
        $reponse = $this->scan(UploadedFile::fake()->createWithContent('cin.png', "\x89PNG\r\n\x1a\n CORROMPU"));

        $reponse->assertStatus(422);
    }

    public function test_polyglot_pdf_deguise_en_jpg_est_rejete(): void
    {
        // Contenu PDF avec extension .jpg : incohérence contenu/extension
        $reponse = $this->scan(UploadedFile::fake()->createWithContent('photo.jpg', "%PDF-1.4\n%%EOF\n"));

        $reponse->assertStatus(422);
    }

    public function test_nom_de_fichier_avec_path_traversal_n_est_jamais_utilise_comme_chemin(): void
    {
        $base = UploadedFile::fake()->image('photo.jpg', 100, 100);

        // Nom client réellement malveillant (contrôlé par l'attaquant)
        $fichier = new UploadedFile(
            $base->getRealPath(),
            '../../evil.jpg',
            'image/jpeg',
            null,
            true,
        );

        $service = app(IdentityDocumentService::class);
        $chemin = $service->uploadDocument($fichier, 'cin');

        // Le stockage utilise TOUJOURS le répertoire temporaire privé +
        // un nom aléatoire : jamais le nom fourni par le client
        $this->assertStringStartsWith(
            (string) config('identity.repertoire_temporaire').'/',
            $chemin,
        );
        $this->assertStringNotContainsString('..', $chemin);
        $this->assertStringNotContainsString('evil', $chemin);

        // Et le fichier reste bien CONFINÉ dans la zone temporaire
        $fichiers = Storage::disk('local')->allFiles();
        $this->assertSame([$chemin], $fichiers);

        $service->supprimerDocument($chemin);
    }

    public function test_type_de_document_invalide_est_rejete(): void
    {
        $reponse = $this->scan(UploadedFile::fake()->image('doc.png', 100, 100), type: 'carte_bus');

        $reponse->assertStatus(422);
    }

    /* ── OCR : extraction passeport / données manquantes (§21) ── */

    public function test_scan_passeport_avec_mrz_valide(): void
    {
        $this->brancheMoteurOcr(self::mrzPasseport());

        $reponse = $this->scan(UploadedFile::fake()->image('passeport.png', 400, 300), 'passeport');

        $reponse->assertOk()
            ->assertJsonPath('document_type', 'passeport')
            ->assertJsonPath('data.cin', 'AB1234567')
            ->assertJsonPath('data.nom', 'BENALI')
            ->assertJsonPath('data.prenom', 'MOHAMED')
            ->assertJsonPath('data.nationalite', 'TUN')
            ->assertJsonPath('data.date_naissance', '1982-02-15')
            ->assertJsonPath('data.sexe', 'M')
            ->assertJsonPath('mrz_valide', true)
            ->assertJsonPath('confidence.cin', 0.98);
    }

    public function test_scan_muet_ne_bloque_pas_linscription(): void
    {
        // Moteur sans texte (cas NullOcrEngine par défaut) : fail-soft
        $this->brancheMoteurOcr('');

        $reponse = $this->scan(UploadedFile::fake()->image('cin.png', 100, 100));

        $reponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cin', null)
            ->assertJsonPath('existing_student', false);

        // Avertissement de vérification manuelle (§8/§16)
        $alertes = collect($reponse->json('alerts'));
        $this->assertTrue($alertes->isNotEmpty());
    }

    public function test_cin_ambigu_exige_verification_humaine(): void
    {
        // §6 : « 1234567B » → confiance dégradée, alerte de vérification
        $this->brancheMoteurOcr("CIN : 1234567B\nNom : BEN ALI\nPrénom : Mohamed\nNé(e) le : 15/02/2002");

        $reponse = $this->scan(UploadedFile::fake()->image('cin.png', 100, 100));

        $reponse->assertOk()
            ->assertJsonPath('data.cin', '1234567B');

        $this->assertLessThan(
            (float) config('identity.confiance.moyen'),
            $reponse->json('confidence.cin'),
        );

        $this->assertTrue(
            collect($reponse->json('alerts'))->contains(fn ($a) => str_contains($a, 'vérification manuelle')),
            'Une alerte de vérification manuelle doit être présente (§8).',
        );
    }

    /* ── Base de données (§10-11, §21) ─────────────────────────── */

    public function test_cin_existant_avec_donnees_identiques_prefill_le_dossier(): void
    {
        $etudiant = $this->creerEtudiantDossier();

        $reponse = $this->scan(UploadedFile::fake()->image('cin.png', 100, 100));

        $reponse->assertOk()
            ->assertJsonPath('existing_student', true)
            ->assertJsonPath('source', 'dossier_existant')
            ->assertJsonPath('etudiant.cin', '12345678')
            ->assertJsonPath('etudiant.email', $etudiant->email);

        // OCR = BD → aucune divergence signalée
        $this->assertArrayNotHasKey('divergences', $reponse->json());
    }

    public function test_cin_existant_avec_donnees_differentes_signale_les_divergences(): void
    {
        // BD : « Mohammed » vs OCR : « Mohamed » → divergence, jamais écrasée
        $this->creerEtudiantDossier(['prenom' => 'Mohammed']);

        $reponse = $this->scan(UploadedFile::fake()->image('cin.png', 100, 100));

        $reponse->assertOk()->assertJsonPath('existing_student', true);

        $divergences = $reponse->json('divergences');
        $this->assertIsArray($divergences);
        $this->assertSame('prenom', $divergences[0]['champ']);
        $this->assertSame('MOHAMED', $divergences[0]['extrait']);
        $this->assertSame('Mohammed', $divergences[0]['enregistre']);

        // La donnée BD fait foi (§11 : ne pas écraser une donnée validée)
        $this->assertSame('Mohammed', $reponse->json('etudiant.prenom'));
    }

    public function test_cin_existant_avec_autre_date_n_est_pas_revelee(): void
    {
        // Fail-closed (§P0-4) : numéro correct mais date différente →
        // le dossier n'est PAS révélé (aucune oracle d'énumération)
        $this->creerEtudiantDossier(['date_naissance' => '1999-09-09']);

        $reponse = $this->scan(UploadedFile::fake()->image('cin.png', 100, 100));

        $reponse->assertOk()
            ->assertJsonPath('existing_student', false)
            ->assertJsonMissing(['etudiant']);
    }

    public function test_scan_ne_cree_jamais_de_doublon(): void
    {
        $this->creerEtudiantDossier();

        $this->scan(UploadedFile::fake()->image('cin.png', 100, 100));

        // §6/§23 : le scan ne crée RIEN ; l'inscription finale reste la
        // seule voie d'écriture et l'unicité CIN interdit le doublon.
        $this->assertSame(1, Etudiant::count());
    }

    public function test_inscription_avec_cin_scannee_existante_est_refusee(): void
    {
        $this->creerEtudiantDossier();
        $faculte = Faculte::first();

        $reponse = $this->post(route('inscription.store'), [
            'cin' => '12345678',
            'nom' => 'BEN ALI',
            'prenom' => 'Mohamed',
            'email' => 'nouveau.scan@example.com',
            'date_naissance' => '2002-02-15',
            'sexe' => 'M',
            'faculte_id' => $faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Rue Test',
            'ville' => 'Sfax',
            'code_postal' => '30000',
            'annee_bac' => '2020',
            'password' => 'motdepasse1',
            'password_confirmation' => 'motdepasse1',
        ]);

        $reponse->assertSessionHasErrors(['cin']);
        $this->assertSame(1, Etudiant::count());
    }

    /* ── Sécurité (§12/§15/§19, §21) ───────────────────────────── */

    public function test_la_reponse_ne_contient_jamais_le_mot_de_passe_ni_le_hash(): void
    {
        $etudiant = $this->creerEtudiantDossier();

        $reponse = $this->scan(UploadedFile::fake()->image('cin.png', 100, 100));

        $corps = $reponse->getContent();
        $this->assertStringNotContainsString('password', $corps);
        $this->assertStringNotContainsString('password_hash', $corps);
        $this->assertStringNotContainsString('remember_token', $corps);
        $this->assertStringNotContainsString('$2y$', $corps);
        $this->assertStringNotContainsString('MotDePasseScan123', $corps);
        $this->assertStringNotContainsString((string) $etudiant->id, 'Aucun identifiant interne n\'est exposé.');
    }

    public function test_le_document_temporaire_est_supprime_apres_le_scan(): void
    {
        // §13 option A : upload → OCR → suppression systématique
        $this->scan(UploadedFile::fake()->image('cin.png', 100, 100));

        $fichiers = Storage::disk('local')->allFiles((string) config('identity.repertoire_temporaire'));
        $this->assertSame([], $fichiers, 'Aucune pièce d\'identité ne doit survivre au scan.');
    }

    public function test_le_document_est_supprime_meme_si_le_tiers_erreur(): void
    {
        // Une exception OCR ne doit JAMAIS laisser le fichier sur disque
        $this->app->instance(OcrEngineInterface::class, new class implements OcrEngineInterface
        {
            public function rawText(string $filePath, string $langue = OcrEngineInterface::LANGUE_ARABE_LATIN, ?int $psm = null): string
            {
                throw new \RuntimeException('panne moteur');
            }
        });

        try {
            $this->scan(UploadedFile::fake()->image('cin.png', 100, 100));
        } catch (\RuntimeException) {
            // attendu : l'exception remonte, mais le finally a nettoyé
        }

        $this->assertSame(
            [],
            Storage::disk('local')->allFiles((string) config('identity.repertoire_temporaire')),
        );
    }

    public function test_rate_limiting_bloque_les_scans_massifs(): void
    {
        config(['identity.throttle.scan' => 3]);

        for ($i = 1; $i <= 3; $i++) {
            $this->scan(UploadedFile::fake()->image("scan{$i}.png", 50, 50))
                ->assertOk("Le scan {$i}/3 aurait dû passer.");
        }

        $this->scan(UploadedFile::fake()->image('scan4.png', 50, 50))
            ->assertStatus(429);
    }

    public function test_l_endpoint_est_public_mais_protege_par_csrf(): void
    {
        $route = Route::getRoutes()->match(
            Request::create('/inscription/identity/scan', 'POST')
        );

        // Public : aucun middleware d'authentification
        $this->assertNotContains('auth', $route->gatherMiddleware());

        // Groupe web ⇒ CSRF obligatoire (POST sans jeton → 419 hors tests)
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains(PreventRequestForgery::class, Route::getMiddlewareGroups()['web']);
    }

    public function test_la_page_inscription_embarque_le_parcours_de_scan(): void
    {
        $reponse = $this->get(route('inscription.create'));

        $reponse->assertOk()
            ->assertSee('televerserDocumentScan', false)
            ->assertSee('choisirTypeDocument', false)
            ->assertSee('appliquerScan', false)
            ->assertSee('supprimé immédiatement', false);
    }
}
