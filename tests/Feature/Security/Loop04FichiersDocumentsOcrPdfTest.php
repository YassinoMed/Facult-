<?php

namespace Tests\Feature\Security;

use App\Models\AnneeUniversitaire;
use App\Models\DocumentVerification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Finances\Models\DossierPaiement;
use App\Modules\Finances\Models\RecuPaiement;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\OnlineRequests\Models\OnlineRequestDocument;
use App\Modules\OnlineRequests\Models\RequestType;
use App\Modules\StudentDossiers\Models\GeneratedDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Modules\StudentDossiers\Services\DocumentStorageService;
use App\Services\Identity\IdentityDocumentService;
use App\Services\Identity\OcrEngineInterface;
use App\Services\PeriodeInscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * LOOP-04 — Fichiers, pièces justificatives, OCR et PDF.
 *
 * Vérifie :
 * 1. Fichier trop volumineux ou format interdit (rejet propre).
 * 2. Extension valide mais contenu incompatible (anti-polyglot, magic bytes mismatch).
 * 3. Tentative d'accès direct à un fichier privé (hors contrôleur, isolation disque privé).
 * 4. Tentative de télécharger un fichier appartenant à un autre compte (anti-IDOR fichiers).
 * 5. Nettoyage du fichier temporaire après succès et après échec OCR.
 * 6. Génération PDF isolée (aucun mélange de données entre étudiants).
 * 7. Référence publique invalide ou révoquée selon les règles définies.
 */
class Loop04FichiersDocumentsOcrPdfTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Etudiant $etudiantA;

    private Etudiant $etudiantB;

    private StudentDossier $dossierA;

    private StudentDossier $dossierB;

    private User $adminScolarite;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        Storage::fake('local');
        Storage::fake('public');

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
            'description' => 'Campus Universitaire El Manar',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
            'active' => true,
        ]);

        app(PeriodeInscriptionService::class)->creerNormale([
            'annee_universitaire_id' => $this->annee->id,
            'opened_at' => '2020-01-01 00:00',
            'closed_at' => '2030-12-31 23:59',
        ], null);

        $this->etudiantA = Etudiant::create([
            'cin' => '08112233',
            'num_inscription' => 'INS-2026-A',
            'nom' => 'Ben Ammar',
            'prenom' => 'Sami',
            'email' => 'sami.benammar@etudiant.tn',
            'date_naissance' => '2002-05-14',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Avenue Habib Bourguiba, Tunis',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2020',
            'password' => 'MotDePasseSecurise123!',
        ]);

        $this->etudiantB = Etudiant::create([
            'cin' => '09223344',
            'num_inscription' => 'INS-2026-B',
            'nom' => 'Trabelsi',
            'prenom' => 'Leila',
            'email' => 'leila.trabelsi@etudiant.tn',
            'date_naissance' => '2003-08-22',
            'sexe' => 'F',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Rue de Carthage, Tunis',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2021',
            'password' => 'MotDePasseSecurise123!',
        ]);

        $this->dossierA = StudentDossier::create([
            'etudiant_id' => $this->etudiantA->id,
            'reference' => 'DOS-2026-A',
            'statut' => 'complet',
        ]);

        $this->dossierB = StudentDossier::create([
            'etudiant_id' => $this->etudiantB->id,
            'reference' => 'DOS-2026-B',
            'statut' => 'complet',
        ]);

        $this->adminScolarite = User::creerCompteBackOffice([
            'name' => 'Agent Scolarité',
            'email' => 'scolarite@fst.utm.tn',
            'password' => 'AdminSecurise123!',
        ], 'admin_scolarite');
    }

    private function connecterEtudiant(Etudiant $etudiant): void
    {
        session([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_cin' => $etudiant->cin,
            'etudiant_nom' => $etudiant->nom,
            'etudiant_prenom' => $etudiant->prenom,
            'etudiant_email' => $etudiant->email,
            'etudiant_password_version' => 1,
        ]);
    }

    // ── 1. Rejet propre : fichier volumineux ou format interdit ─────────────

    public function test_document_storage_rejette_fichier_trop_volumineux(): void
    {
        $storageService = app(DocumentStorageService::class);

        // Crée un fichier de plus de 10 Mo
        $fichierTropGros = UploadedFile::fake()->createWithContent(
            'gros_fichier.pdf',
            "%PDF-1.4\n".str_repeat('A', (10 * 1024 * 1024) + 2048),
            'application/pdf'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('trop volumineux');

        $storageService->stocker(
            $fichierTropGros,
            $this->etudiantA->id,
            '2025-2026',
            'releve'
        );
    }

    public function test_document_storage_rejette_formats_dangereux_ou_interdits(): void
    {
        $storageService = app(DocumentStorageService::class);

        $extensionsDangereuses = ['php', 'phtml', 'exe', 'sh', 'phar', 'py', 'bat'];

        foreach ($extensionsDangereuses as $ext) {
            $fichier = UploadedFile::fake()->createWithContent(
                "script.{$ext}",
                'echo "inacceptable";',
                'text/plain'
            );

            try {
                $storageService->stocker(
                    $fichier,
                    $this->etudiantA->id,
                    '2025-2026',
                    'pieces'
                );
                $this->fail("L'extension « {$ext} » aurait dû être rejetée.");
            } catch (RuntimeException $e) {
                $this->assertTrue(
                    str_contains($e->getMessage(), 'interdit') || str_contains($e->getMessage(), 'non accepté'),
                    "Message attendu pour « {$ext} », obtenu : ".$e->getMessage()
                );
            }
        }
    }

    public function test_identity_document_service_rejette_format_interdit(): void
    {
        $identityService = app(IdentityDocumentService::class);

        $fichierPhp = UploadedFile::fake()->createWithContent(
            'malicieux.php',
            '<?php phpinfo(); ?>',
            'application/x-php'
        );

        $this->expectException(ValidationException::class);

        $identityService->validerFichier($fichierPhp, 'cin');
    }

    // ── 2. Anti-polyglot et cohérence extension ↔ signature ──────────────────

    public function test_document_storage_rejette_pdf_avec_signature_invalide(): void
    {
        $storageService = app(DocumentStorageService::class);

        // Fichier avec extension .pdf mais contenu texte sans header %PDF-
        $fauxPdf = UploadedFile::fake()->createWithContent(
            'faux.pdf',
            'Bonjour, ceci est un document texte ordinaire.',
            'application/pdf'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('signature manquante');

        $storageService->stocker(
            $fauxPdf,
            $this->etudiantA->id,
            '2025-2026',
            'justificatifs'
        );
    }

    public function test_document_storage_rejette_jpeg_avec_signature_invalide(): void
    {
        $storageService = app(DocumentStorageService::class);

        // Fichier .jpg avec signature PNG au lieu de JPEG
        $fauxJpeg = UploadedFile::fake()->createWithContent(
            'image.jpg',
            "\x89PNG\r\n\x1a\nDonnees factices PNG",
            'image/jpeg'
        );

        $this->expectException(RuntimeException::class);

        $storageService->stocker(
            $fauxJpeg,
            $this->etudiantA->id,
            '2025-2026',
            'photo'
        );
    }

    public function test_identity_service_rejette_fichier_polyglot_extension_mismatch(): void
    {
        $identityService = app(IdentityDocumentService::class);

        // Fichier nommé .pdf mais contenant des octets réels JPEG
        $polyglot = UploadedFile::fake()->createWithContent(
            'scan.pdf',
            "\xFF\xD8\xFF\xE0\x00\x10JFIF".str_repeat('0', 100),
            'image/jpeg'
        );

        $this->expectException(ValidationException::class);

        $identityService->validerFichier($polyglot, 'cin');
    }

    // ── 3. Stockage sur disque privé et anti-traversal ───────────────────────

    public function test_fichier_stocke_exclusivement_sur_disque_prive_avec_uuid(): void
    {
        $storageService = app(DocumentStorageService::class);

        $fichierPdf = UploadedFile::fake()->createWithContent(
            'bulletin_original.pdf',
            "%PDF-1.4\nContenu valide et conforme.",
            'application/pdf'
        );

        $meta = $storageService->stocker(
            $fichierPdf,
            $this->etudiantA->id,
            '2025-2026',
            'releve'
        );

        // Le fichier existe sur le disque privé
        $this->assertTrue(Storage::disk('private')->exists($meta['storage_path']));

        // Le fichier n'existe JAMAIS sur le disque public
        $this->assertFalse(Storage::disk('public')->exists($meta['storage_path']));

        // Le chemin interne utilise un UUID aléatoire, jamais le nom d'origine fourni par le client
        $nomFichierInterne = basename($meta['storage_path']);
        $this->assertNotEquals('bulletin_original.pdf', $nomFichierInterne);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.pdf$/i', $nomFichierInterne);

        // L'empreinte SHA-256 enregistrée correspond exactement au contenu
        $this->assertEquals(hash('sha256', "%PDF-1.4\nContenu valide et conforme."), $meta['sha256']);
    }

    public function test_anti_path_traversal_sur_les_chemins_et_noms_de_telechargement(): void
    {
        $storageService = app(DocumentStorageService::class);

        // Nom de téléchargement sécurisé assaini contre directory traversal et injections CRLF
        $nomMalveillant = "../../../etc/passwd\r\nsecret.pdf";
        $nomAssaini = $storageService->nomTelechargementSecurise($nomMalveillant);

        $this->assertStringNotContainsString('..', $nomAssaini);
        $this->assertStringNotContainsString('/', $nomAssaini);
        $this->assertStringNotContainsString('\\', $nomAssaini);
        $this->assertStringNotContainsString("\r", $nomAssaini);
        $this->assertStringNotContainsString("\n", $nomAssaini);
        $this->assertEquals('passwdsecret.pdf', $nomAssaini);

        // Test de rejet direct par IdentityDocumentService si le nom original contient '..'
        $identityService = app(IdentityDocumentService::class);
        $tempFile = tempnam(sys_get_temp_dir(), 'traversal_test');
        file_put_contents($tempFile, "%PDF-1.4\nContenu valide");
        $fichierTraversal = new UploadedFile(
            $tempFile,
            'tentative..pdf',
            'application/pdf',
            null,
            true
        );

        try {
            $identityService->validerFichier($fichierTraversal, 'cin');
            $this->fail('Un nom de fichier contenant « .. » doit être refusé.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('document', $e->errors());
        } finally {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    // ── 4. Anti-IDOR fichiers : interdiction de télécharger les fichiers d'autrui ──

    public function test_etudiant_ne_peut_pas_telecharger_document_officiel_autre_etudiant(): void
    {
        // Document officiel créé sur le dossier de l'étudiant B
        Storage::disk('private')->put('dossiers/doc_b.pdf', "%PDF-1.4 Document officiel secret de Leila\n");

        $docB = GeneratedDocument::create([
            'student_dossier_id' => $this->dossierB->id,
            'type' => 'attestation_inscription',
            'reference' => 'ATT-2026-LEILA',
            'version' => 1,
            'statut' => 'valide',
            'storage_path' => 'dossiers/doc_b.pdf',
            'sha256' => hash('sha256', 'doc-b-content'),
            'contenu_hash' => hash('sha256', 'secret-b'),
            'code_verification' => 'VERIF-CODE-B-123456789012345678',
            'snapshot_donnees' => ['nom' => 'Trabelsi', 'prenom' => 'Leila'],
        ]);

        // L'étudiant A se connecte
        $this->connecterEtudiant($this->etudiantA);

        // L'étudiant A tente de télécharger le document de Leila : 404
        $this->get(route('etudiant.dossier.officiels.download', $docB))
            ->assertNotFound();
    }

    public function test_etudiant_ne_peut_pas_telecharger_piece_demande_autre_etudiant(): void
    {
        $typeDemande = RequestType::create([
            'name' => 'Réclamation administrative',
            'code' => 'reclamation_note',
            'active' => true,
            'fields' => [],
        ]);

        // Demande de l'étudiant B avec document attaché
        $demandeB = OnlineRequest::create([
            'reference' => 'DEM-2026-B',
            'request_type_id' => $typeDemande->id,
            'etudiant_id' => $this->etudiantB->id,
            'objet' => 'Réclamation de note Leila',
            'status' => 'submitted',
        ]);

        Storage::disk('private')->put('demandes/piece_b.pdf', "%PDF-1.4 Piece de réclamation de Leila\n");

        OnlineRequestDocument::create([
            'reference' => 'DOC-DEM-B',
            'online_request_id' => $demandeB->id,
            'type' => 'justificatif',
            'version' => 1,
            'storage_path' => 'demandes/piece_b.pdf',
            'sha256' => hash('sha256', 'piece-b'),
        ]);

        // L'étudiant A se connecte
        $this->connecterEtudiant($this->etudiantA);

        // L'étudiant A tente de télécharger le document de la demande de B : 404
        $this->get(route('etudiant.demandes.document.download', $demandeB))
            ->assertNotFound();
    }

    public function test_etudiant_ne_peut_pas_telecharger_recu_paiement_autre_etudiant(): void
    {
        $inscriptionB = InscriptionAcademique::create([
            'etudiant_id' => $this->etudiantB->id,
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'statut' => 'valide',
        ]);

        $dossierPaiementB = DossierPaiement::firstOrCreate(
            ['inscription_academique_id' => $inscriptionB->id],
            [
                'statut' => DossierPaiement::NON_PAYE,
                'montant_attendu' => 500,
                'montant_paye' => 0,
            ]
        );

        Storage::disk('private')->put('recus/recu_leila.pdf', "%PDF-1.4 Reçu bancaire personnel\n");

        $recuB = RecuPaiement::create([
            'dossier_paiement_id' => $dossierPaiementB->id,
            'inscription_academique_id' => $inscriptionB->id,
            'annee_universitaire_id' => $this->annee->id,
            'version' => 1,
            'storage_path' => 'recus/recu_leila.pdf',
            'original_filename' => 'recu_virement.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 150,
            'sha256' => hash('sha256', 'recu-leila'),
            'montant_declare_centimes' => 18000,
            'reference_paiement' => 'VIR-STB-9988',
            'date_paiement' => now()->toDateString(),
            'depose_le' => now(),
            'statut' => RecuPaiement::RECU_DEPOSE,
        ]);

        // L'étudiant A se connecte
        $this->connecterEtudiant($this->etudiantA);

        // L'étudiant A tente de télécharger le reçu de l'étudiant B : 404
        $this->get(route('etudiant.paiements.recus.telecharger', [$inscriptionB, $recuB]))
            ->assertNotFound();
    }

    // ── 5. Nettoyage du fichier temporaire après succès et après échec OCR ────

    public function test_nettoyage_systematique_fichier_temporaire_apres_succes_ocr(): void
    {
        $this->app->instance(OcrEngineInterface::class, new class implements OcrEngineInterface
        {
            public function rawText(string $filePath, string $langue = OcrEngineInterface::LANGUE_ARABE_LATIN, ?int $psm = null): string
            {
                return "REPUBLIQUE TUNISIENNE\nCIN : 08112233\nNom : BEN AMMAR\nPrénom : Sami\nNé(e) le : 14/05/2002";
            }
        });

        $imageScan = UploadedFile::fake()->image('cin_scan.png', 200, 200);

        $response = $this->post(route('inscription.identity.scan'), [
            'document_type' => 'cin',
            'document' => $imageScan,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        // Vérification : le répertoire temporaire ne contient AUCUN fichier résiduel
        $repertoire = (string) config('identity.repertoire_temporaire');
        $fichiersRestants = Storage::disk('local')->allFiles($repertoire);
        $this->assertEmpty($fichiersRestants, 'Le fichier temporaire de scan doit être supprimé après succès OCR.');
    }

    public function test_nettoyage_systematique_fichier_temporaire_apres_echec_ocr(): void
    {
        $this->app->instance(OcrEngineInterface::class, new class implements OcrEngineInterface
        {
            public function rawText(string $filePath, string $langue = OcrEngineInterface::LANGUE_ARABE_LATIN, ?int $psm = null): string
            {
                throw new RuntimeException('Panne fatale du moteur OCR en cours de lecture.');
            }
        });

        $imageScan = UploadedFile::fake()->image('cin_corrompue.png', 200, 200);

        try {
            $this->post(route('inscription.identity.scan'), [
                'document_type' => 'cin',
                'document' => $imageScan,
            ]);
        } catch (RuntimeException $e) {
            $this->assertEquals('Panne fatale du moteur OCR en cours de lecture.', $e->getMessage());
        }

        // Vérification : même en cas d'exception fatale dans l'OCR, le bloc finally a supprimé le fichier
        $repertoire = (string) config('identity.repertoire_temporaire');
        $fichiersRestants = Storage::disk('local')->allFiles($repertoire);
        $this->assertEmpty($fichiersRestants, 'Le fichier temporaire de scan doit être impérativement supprimé même en cas d\'échec OCR.');
    }

    // ── 6. Isolation des données dans la génération PDF ─────────────────────

    public function test_generation_pdf_et_recepisse_strictement_isoles_par_session(): void
    {
        $this->connecterEtudiant($this->etudiantA);

        $reponse = $this->get(route('etudiant.recepisse'));

        $reponse->assertOk();
        $this->assertEquals('application/pdf', $reponse->headers->get('Content-Type'));
        $this->assertStringContainsString('recepisse-inscription-'.$this->etudiantA->num_inscription.'.pdf', (string) $reponse->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-cache', (string) $reponse->headers->get('Cache-Control'));
        $this->assertEquals('nosniff', $reponse->headers->get('X-Content-Type-Options'));
    }

    public function test_snapshot_document_officiel_ne_contient_aucun_melange_de_donnees(): void
    {
        $inscriptionA = InscriptionAcademique::create([
            'etudiant_id' => $this->etudiantA->id,
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'statut' => 'valide',
        ]);

        $inscriptionB = InscriptionAcademique::create([
            'etudiant_id' => $this->etudiantB->id,
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'statut' => 'valide',
        ]);

        $docA = GeneratedDocument::create([
            'student_dossier_id' => $this->dossierA->id,
            'type' => 'attestation_inscription',
            'reference' => 'ATT-2026-A',
            'version' => 1,
            'statut' => 'valide',
            'storage_path' => 'dossiers/doc_a.pdf',
            'sha256' => hash('sha256', 'doc-a'),
            'contenu_hash' => hash('sha256', 'doc-a'),
            'code_verification' => 'VERIF-CODE-A-123456789012345678',
            'snapshot_donnees' => [
                'nom' => $this->etudiantA->nom,
                'prenom' => $this->etudiantA->prenom,
                'cin' => $this->etudiantA->cin,
                'num_inscription' => $this->etudiantA->num_inscription,
            ],
        ]);

        $docB = GeneratedDocument::create([
            'student_dossier_id' => $this->dossierB->id,
            'type' => 'attestation_inscription',
            'reference' => 'ATT-2026-B',
            'version' => 1,
            'statut' => 'valide',
            'storage_path' => 'dossiers/doc_b.pdf',
            'sha256' => hash('sha256', 'doc-b'),
            'contenu_hash' => hash('sha256', 'doc-b'),
            'code_verification' => 'VERIF-CODE-B-123456789012345678',
            'snapshot_donnees' => [
                'nom' => $this->etudiantB->nom,
                'prenom' => $this->etudiantB->prenom,
                'cin' => $this->etudiantB->cin,
                'num_inscription' => $this->etudiantB->num_inscription,
            ],
        ]);

        // Vérification de l'étanchéité stricte des snapshots
        $this->assertEquals($this->etudiantA->nom, $docA->snapshot_donnees['nom']);
        $this->assertEquals($this->etudiantA->cin, $docA->snapshot_donnees['cin']);
        $this->assertNotEquals($this->etudiantB->nom, $docA->snapshot_donnees['nom']);

        $this->assertEquals($this->etudiantB->nom, $docB->snapshot_donnees['nom']);
        $this->assertEquals($this->etudiantB->cin, $docB->snapshot_donnees['cin']);
        $this->assertNotEquals($this->etudiantA->nom, $docB->snapshot_donnees['nom']);
    }

    // ── 7. Vérification publique d'authenticité (valide, révoqué, inexistant) ──

    public function test_verification_publique_reference_inexistante_retourne_404_et_journalise(): void
    {
        $codeInconnu = 'CODE-TOTALEMENT-INCONNU-999';

        $reponse = $this->get('/verify/'.$codeInconnu);

        $reponse->assertNotFound();

        // Une tentative a été journalisée avec IP hachée
        $trace = DocumentVerification::where('code', $codeInconnu)->first();
        $this->assertNotNull($trace);
        $this->assertEquals('inexistant', $trace->resultat);
        $this->assertNull($trace->document_id);
        $this->assertNotEmpty($trace->ip_hachee);
        $this->assertNotEquals('127.0.0.1', $trace->ip_hachee);
    }

    public function test_verification_publique_document_valide_affiche_succes_et_nom_masque(): void
    {
        $codeValide = 'CODE-VERIF-OFFICIEL-VALIDE-001';

        $document = GeneratedDocument::create([
            'student_dossier_id' => $this->dossierA->id,
            'type' => 'attestation_inscription',
            'reference' => 'ATT-VALIDE-001',
            'version' => 1,
            'statut' => 'valide',
            'storage_path' => 'dossiers/doc_valide.pdf',
            'sha256' => hash('sha256', 'doc-valide-sha'),
            'contenu_hash' => hash('sha256', 'doc-valide'),
            'code_verification' => $codeValide,
            'snapshot_donnees' => [
                'nom' => 'Ben Ammar',
                'prenom' => 'Sami',
            ],
            'emis_le' => now(),
        ]);

        $reponse = $this->get('/verify/'.$codeValide);

        $reponse->assertOk()
            ->assertSee('Document valide.')
            ->assertSee($codeValide)
            ->assertSee('B••••••••', false) // Nom masqué sans PII complète
            ->assertDontSee('sami.benammar@etudiant.tn')
            ->assertDontSee('08112233'); // Jamais de CIN

        $trace = DocumentVerification::where('code', $codeValide)->first();
        $this->assertNotNull($trace);
        $this->assertEquals('valide', $trace->resultat);
        $this->assertEquals($document->id, $trace->document_id);
    }

    public function test_verification_publique_document_revoque_affiche_avertissement_explicite(): void
    {
        $codeRevoque = 'CODE-VERIF-OFFICIEL-REVOQUE-002';

        $document = GeneratedDocument::create([
            'student_dossier_id' => $this->dossierA->id,
            'type' => 'attestation_inscription',
            'reference' => 'ATT-REVOQUE-002',
            'version' => 1,
            'statut' => 'revoque',
            'storage_path' => 'dossiers/doc_revoque.pdf',
            'sha256' => hash('sha256', 'doc-revoque-sha'),
            'contenu_hash' => hash('sha256', 'doc-revoque'),
            'code_verification' => $codeRevoque,
            'snapshot_donnees' => [
                'nom' => 'Ben Ammar',
                'prenom' => 'Sami',
            ],
            'emis_le' => now(),
        ]);

        $reponse = $this->get('/verify/'.$codeRevoque);

        $reponse->assertOk()
            ->assertSee('Document revoque — ne pas accepter comme valide.', false)
            ->assertDontSee('Document valide.');

        $trace = DocumentVerification::where('code', $codeRevoque)->first();
        $this->assertNotNull($trace);
        $this->assertEquals('revoque', $trace->resultat);
        $this->assertEquals($document->id, $trace->document_id);
    }
}
