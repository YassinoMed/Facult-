<?php

namespace Tests\Feature;

use App\Console\Commands\MarquerDocumentsExpires;
use App\Console\Commands\PurgerOrphelins;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Semestre;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\StudentDossiers\Models\GeneratedDocument;
use App\Modules\StudentDossiers\Services\DocumentStorageService;
use App\Modules\StudentDossiers\Services\Pdf\OfficialDocumentService;
use App\Modules\StudentDossiers\Services\StudentDossierService;
use App\Services\Documents\DefaultDocumentGenerator;
use App\Services\Documents\DocumentVerificationService;
use App\Support\QrCodeSvg;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * LOT 07 — Coffre-fort documentaire, attestations officielles et vérification QR.
 *
 * Couvre :
 * 1. Générateur QR natif ISO/IEC 18004 (SVG vectoriel, Reed-Solomon, zéro fuite PII) ;
 * 2. Génération de documents officiels avec injection QR et stockage privé hors web-root ;
 * 3. Cycle de vie et vérification publique (valide, expiré, révoqué, remplacé, inconnu) ;
 * 4. Protection stricte anti-IDOR sur les téléchargements étudiants et administrateurs ;
 * 5. Nettoyage sécurisé des noms de fichiers et purge des fichiers temporaires (finally) ;
 * 6. Commandes d'expiration et de purge orpheline en mode simulation/exécution.
 */
class CoffreFortAttestationsQrTest extends TestCase
{
    use RefreshDatabase;

    private User $adminScolarite;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Etudiant $etudiantA;

    private Etudiant $etudiantB;

    private InscriptionAcademique $inscriptionA;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.student-dossiers.active' => true]);
        Storage::fake('private');
        Storage::fake('local');

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FST',
            'description' => 'FST Campus Nord',
            'active' => true,
        ]);

        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Médecine',
            'code' => 'FMP',
            'description' => 'FMP Campus Santé',
            'active' => true,
        ]);

        $this->adminScolarite = User::creerCompteBackOffice([
            'name' => 'Agent Scolarité Central',
            'email' => 'scolarite.central@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'L-INFO',
            'nom' => 'Licence Informatique',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $niveau = Niveau::create([
            'programme_id' => $this->programme->id,
            'code' => 'L1',
            'nom' => 'Licence 1ère année',
            'ordre' => 1,
        ]);

        $semestreRef = SemestreLmd::create([
            'niveau_id' => $niveau->id,
            'code' => 'S1',
            'nom' => 'Semestre 1',
            'numero' => 1,
            'ordre' => 1,
        ]);

        $ue = UniteEnseignement::create([
            'semestre_id' => $semestreRef->id,
            'programme_id' => $this->programme->id,
            'code' => 'UE-FOND1',
            'nom' => 'Fondamentaux Info',
            'credits' => 6,
            'coefficient' => 2,
        ]);

        $matiere = Matiere::create([
            'code' => 'ALGO101',
            'nom' => 'Algorithmique 1',
            'ue_id' => $ue->id,
            'niveau' => 'L1',
            'semestre' => 1,
            'credits' => 6,
            'coefficient' => 2,
        ]);

        $this->etudiantA = Etudiant::create([
            'cin' => '12345678',
            'nom' => 'Ben Mansour',
            'prenom' => 'Yassine',
            'email' => 'yassine.bm@etudiant.test',
            'date_naissance' => '2003-05-14',
            'sexe' => 'M',
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'num_inscription' => 'INS-2026-0001',
            'adresse' => 'Avenue Habib Bourguiba',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiantA->definirStatut('valide');

        $this->etudiantB = Etudiant::create([
            'cin' => '87654321',
            'nom' => 'Trabelsi',
            'prenom' => 'Amina',
            'email' => 'amina.tr@etudiant.test',
            'date_naissance' => '2004-09-22',
            'sexe' => 'F',
            'faculte_id' => $this->faculteB->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'num_inscription' => 'INS-2026-0002',
            'adresse' => 'Rue de Paris',
            'ville' => 'Sousse',
            'code_postal' => '4000',
            'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiantB->definirStatut('valide');

        $this->inscriptionA = InscriptionAcademique::create([
            'etudiant_id' => $this->etudiantA->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
            'type' => 'premiere',
            'regime' => 'initiale',
            'moyenne_annuelle' => 14.75,
            'credits_obtenus' => 30,
            'credits_total' => 30,
        ]);

        $semestre = Semestre::create([
            'inscription_academique_id' => $this->inscriptionA->id,
            'numero' => 1,
            'moyenne' => 14.75,
            'credits_obtenus' => 30,
            'credits_total' => 30,
        ]);

        Note::create([
            'etudiant_id' => $this->etudiantA->id,
            'inscription_academique_id' => $this->inscriptionA->id,
            'semestre_id' => $semestre->id,
            'matiere_id' => $matiere->id,
            'note' => 14.75,
            'coefficient' => 2,
            'credits' => 6,
            'session' => 'normale',
        ]);
    }

    private function publierDeliberationAdmis(): Deliberation
    {
        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'statut' => 'published',
            'created_by' => $this->adminScolarite->id,
            'published_at' => now(),
        ]);

        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id,
            'etudiant_id' => $this->etudiantA->id,
            'inscription_academique_id' => $this->inscriptionA->id,
            'moyenne' => 14.75,
            'credits_obtenus' => 30,
            'credits_total' => 30,
            'decision' => 'admis',
            'publie' => true,
        ]);

        return $deliberation;
    }

    // ────────────────────────────────────────────────────────────
    // 1. GÉNÉRATEUR QR CODE NATIF (ISO/IEC 18004)
    // ────────────────────────────────────────────────────────────

    public function test_qr_code_svg_generateur_produit_svg_conforme(): void
    {
        $url = 'https://universite.test/verify/ABCDEF1234567890ABCDEF1234567890';
        $svg = QrCodeSvg::generer($url, 120);

        $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $svg);
        $this->assertStringContainsString('viewBox="0 0', $svg);
        $this->assertStringContainsString('width="120" height="120"', $svg);
        $this->assertStringContainsString('shape-rendering="crispEdges"', $svg);
        $this->assertStringContainsString('<rect width="100%" height="100%" fill="#ffffff"', $svg);
        $this->assertStringContainsString('<rect x="', $svg);
        $this->assertStringContainsString('fill="#0f172a"', $svg);
        $this->assertStringEndsWith('</svg>', trim($svg));
    }

    public function test_qr_code_svg_gestion_erreurs_et_limites(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('QR : contenu vide');
        QrCodeSvg::generer('');
    }

    public function test_qr_code_svg_rejet_payload_trop_volumineux(): void
    {
        // 272 > plafond v10-L (271) : l'extension aux versions 7-10 (jetons
        // d'émargement ~169 octets, AppelQrTest) déplace la limite, pas la règle.
        $payloadExcessif = str_repeat('A', 272);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('QR : contenu trop long');
        QrCodeSvg::generer($payloadExcessif);
    }

    public function test_qr_code_supporte_niveaux_m_et_l_dans_les_limites(): void
    {
        // 20 octets => Version 2 M
        $court = QrCodeSvg::generer('https://short.test/v1', 80);
        $this->assertNotEmpty($court);

        // 66 octets => Version 5 M
        $moyen = QrCodeSvg::generer('https://universite.test/verify/A1B2C3D4E5F6G7H8I9J0K1L2M3N4O5P6Q7R8S9T0', 100);
        $this->assertNotEmpty($moyen);

        // 116 octets => Version 8 M (groupes inégaux 38/39)
        $long = QrCodeSvg::generer('https://universite.test/verify/'.str_repeat('X', 85), 100);
        $this->assertNotEmpty($long);

        // 169 octets (jeton émargement réel, AppelQrTest) => Version 9 M
        $jeton = QrCodeSvg::generer(str_repeat('Z', 169), 220);
        $this->assertNotEmpty($jeton);
    }

    // ────────────────────────────────────────────────────────────
    // 2. GÉNÉRATION DE DOCUMENTS OFFICIELS AVEC QR CODE
    // ────────────────────────────────────────────────────────────

    public function test_official_document_service_inclut_qr_code_et_url_verification(): void
    {
        $this->publierDeliberationAdmis();

        $service = app(OfficialDocumentService::class);
        $document = $service->generer($this->etudiantA, 'attestation_reussite', $this->inscriptionA->id, $this->adminScolarite->id);

        $this->assertInstanceOf(GeneratedDocument::class, $document);
        $this->assertSame('valide', $document->statut);
        $this->assertNotNull($document->code_verification);
        $this->assertSame(32, strlen($document->code_verification));

        // Snapshot immuable contient l'URL et le QR Code SVG
        $snapshot = $document->snapshot_donnees;
        $this->assertIsArray($snapshot);
        $this->assertArrayHasKey('urlVerification', $snapshot);
        $this->assertArrayHasKey('codeVerification', $snapshot);
        $this->assertArrayHasKey('qrCodeSvg', $snapshot);

        $this->assertSame(url('/verify/'.$document->code_verification), $snapshot['urlVerification']);
        $this->assertStringContainsString('<svg', $snapshot['qrCodeSvg']);

        // Stockage physique privé hors web-root
        Storage::disk('private')->assertExists($document->storage_path);
        $this->assertStringStartsWith('student-dossiers/generated/', $document->storage_path);
    }

    public function test_default_document_generator_injecte_qr_code(): void
    {
        $generateur = app(DefaultDocumentGenerator::class);
        $doc = $generateur->generer($this->etudiantA, 'attestation_inscription', [
            'inscription_id' => $this->inscriptionA->id,
        ], $this->adminScolarite->id);

        $this->assertInstanceOf(GeneratedDocument::class, $doc);
        $this->assertSame(32, strlen((string) $doc->code_verification));
        $this->assertNotNull($doc->snapshot_donnees);
        $this->assertArrayHasKey('qrCodeSvg', $doc->snapshot_donnees);
        $this->assertStringContainsString('<svg', $doc->snapshot_donnees['qrCodeSvg']);
        Storage::disk('private')->assertExists($doc->storage_path);
    }

    // ────────────────────────────────────────────────────────────
    // 3. PAGE PUBLIQUE DE VÉRIFICATION & STATUTS (ZERO PII)
    // ────────────────────────────────────────────────────────────

    public function test_verification_publique_document_valide_sans_fuite_pii(): void
    {
        $this->publierDeliberationAdmis();

        $document = app(OfficialDocumentService::class)
            ->generer($this->etudiantA, 'attestation_reussite', $this->inscriptionA->id, $this->adminScolarite->id);

        $verification = app(DocumentVerificationService::class);
        $resultat = $verification->verifier($document->code_verification, '192.168.1.50');

        $this->assertTrue($resultat['trouve']);
        $this->assertTrue($resultat['valide']);
        $this->assertSame('valide', $resultat['statut']);
        $this->assertSame('attestation_reussite', $resultat['type']);

        // Masquage strict du nom : premier caractère + puces
        $this->assertStringStartsWith('B', $resultat['nom_masque']);
        $this->assertStringNotContainsString('Mansour', $resultat['nom_masque']);

        // Accès HTTP public sans authentification
        $response = $this->get(route('documents.verify', $document->code_verification));
        $response->assertOk();
        $response->assertSee('Document valide.');
        $response->assertSee($document->code_verification);
        $response->assertDontSee('12345678'); // CIN non divulgué
        $response->assertDontSee('2003-05-14'); // Date naissance non divulguée
        $response->assertDontSee('14.75'); // Notes non divulguées

        // IP hachée en base, jamais en clair
        $this->assertDatabaseMissing('document_verifications', ['ip_hachee' => '192.168.1.50']);
        $this->assertDatabaseHas('document_verifications', [
            'document_id' => $document->id,
            'code' => $document->code_verification,
            'resultat' => 'valide',
        ]);
    }

    public function test_verification_statut_expire_revoque_remplace_et_inconnu(): void
    {
        $this->publierDeliberationAdmis();
        $service = app(OfficialDocumentService::class);
        $verification = app(DocumentVerificationService::class);

        $document = $service->generer($this->etudiantA, 'releve_notes', $this->inscriptionA->id, $this->adminScolarite->id);

        // 1. Expiré par date
        $document->update(['valide_jusquau' => now()->subDay()]);
        $resExpire = $verification->verifier($document->code_verification, '10.0.0.1');
        $this->assertFalse($resExpire['valide']);
        $this->assertSame('expire', $resExpire['statut']);

        // 2. Révoqué
        $document->update(['valide_jusquau' => now()->addYear()]);
        $service->revoquer($document, 'Erreur de saisie scolarité', $this->adminScolarite->id);
        $resRevoque = $verification->verifier($document->code_verification, '10.0.0.1');
        $this->assertFalse($resRevoque['valide']);
        $this->assertSame('revoque', $resRevoque['statut']);

        // 3. Remplacé (supersession d'un document valide lors d'une ré-émission)
        $docActif = $service->generer($this->etudiantA, 'attestation_reussite', $this->inscriptionA->id, $this->adminScolarite->id);
        $this->inscriptionA->update(['moyenne_annuelle' => 15.5]);
        $v2 = $service->generer($this->etudiantA, 'attestation_reussite', $this->inscriptionA->id, $this->adminScolarite->id);
        $resRemplace = $verification->verifier($docActif->code_verification, '10.0.0.1');
        $this->assertFalse($resRemplace['valide']);
        $this->assertSame('remplace', $resRemplace['statut']);

        // 4. Inconnu => 404
        $this->get(route('documents.verify', 'CODE-INEXISTANT-TOTALEMENT-000000'))
            ->assertNotFound();
    }

    // ────────────────────────────────────────────────────────────
    // 4. PROTECTION STRICTE ANTI-IDOR SUR LES TÉLÉCHARGEMENTS
    // ────────────────────────────────────────────────────────────

    public function test_anti_idor_espace_etudiant_mon_dossier(): void
    {
        $this->publierDeliberationAdmis();
        $documentA = app(OfficialDocumentService::class)
            ->generer($this->etudiantA, 'attestation_reussite', $this->inscriptionA->id, $this->adminScolarite->id);

        // Étudiant B authentifié tente de télécharger le document de l'étudiant A
        $this->withSession(['etudiant_auth_id' => $this->etudiantB->id])
            ->get(route('etudiant.dossier.officiels.download', $documentA))
            ->assertNotFound();

        // Étudiant A authentifié accède à son document
        $this->withSession(['etudiant_auth_id' => $this->etudiantA->id])
            ->get(route('etudiant.dossier.officiels.download', $documentA))
            ->assertOk();
    }

    public function test_anti_idor_administration_faculte_etrangere(): void
    {
        $this->publierDeliberationAdmis();
        $documentA = app(OfficialDocumentService::class)
            ->generer($this->etudiantA, 'attestation_reussite', $this->inscriptionA->id, $this->adminScolarite->id);

        // Admin restreint à la Faculté B (FMP)
        $adminFacB = User::creerCompteBackOffice([
            'name' => 'Agent FMP',
            'email' => 'agent.fmp@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');

        // Tentative d'accès au document de la Faculté A (FST) => 403 Forbidden
        $this->actingAs($adminFacB)
            ->get(route('admin.documents-officiels.download', $documentA))
            ->assertForbidden();

        // Admin FST accède normalement
        $this->actingAs($this->adminScolarite)
            ->get(route('admin.documents-officiels.download', $documentA))
            ->assertOk();
    }

    public function test_telechargement_document_revoque_renvoie_410(): void
    {
        $this->publierDeliberationAdmis();
        $document = app(OfficialDocumentService::class)
            ->generer($this->etudiantA, 'attestation_reussite', $this->inscriptionA->id, $this->adminScolarite->id);

        app(OfficialDocumentService::class)->revoquer($document, 'Erreur délibération', $this->adminScolarite->id);

        $this->actingAs($this->adminScolarite)
            ->get(route('admin.documents-officiels.download', $document))
            ->assertStatus(410);

        $this->withSession(['etudiant_auth_id' => $this->etudiantA->id])
            ->get(route('etudiant.dossier.officiels.download', $document))
            ->assertStatus(410);
    }

    // ────────────────────────────────────────────────────────────
    // 5. PURGE DES FICHIERS TEMPORAIRES & NETTOYAGE DES NOMS
    // ────────────────────────────────────────────────────────────

    public function test_nettoyage_nom_fichier_anti_header_injection_et_traversal(): void
    {
        $stockage = app(DocumentStorageService::class);

        $nomDangereux = "../../etc/passwd\r\nContent-Disposition: inline;\".pdf";
        $nettoye = $stockage->nomTelechargementSecurise($nomDangereux);

        $this->assertStringNotContainsString('..', $nettoye);
        $this->assertStringNotContainsString('/', $nettoye);
        $this->assertStringNotContainsString("\r", $nettoye);
        $this->assertStringNotContainsString("\n", $nettoye);
        $this->assertStringNotContainsString('"', $nettoye);
        $this->assertSame('passwdContent-Disposition: inline;.pdf', $nettoye);
    }

    public function test_document_temporaire_ocr_purge_systematiquement_dans_finally(): void
    {
        // Document temporaire avec faux scan d'identité
        $fakeImage = UploadedFile::fake()->image('cin.jpg', 600, 400);

        $response = $this->postJson(route('inscription.identity.scan'), [
            'document' => $fakeImage,
            'document_type' => 'cin',
        ]);

        $response->assertOk();

        // Le stockage privé temp ne doit contenir aucun fichier persistant après la requête
        $fichiersPrivatifs = Storage::disk('private')->allFiles('identity-temp');
        $this->assertEmpty($fichiersPrivatifs, 'Le fichier temporaire de scan aurait dû être purgé dans le bloc finally.');
    }

    // ────────────────────────────────────────────────────────────
    // 6. COMMANDES DE PURGE DES ORPHELINS ET MARQUAGE EXPIRÉS
    // ────────────────────────────────────────────────────────────

    public function test_commande_marquer_documents_expires_traite_les_echeances(): void
    {
        $dossier = app(StudentDossierService::class)
            ->obtenirOuCreer($this->etudiantA);

        $docPerime = GeneratedDocument::create([
            'reference' => 'TEST-EXP-01',
            'numero' => 'TEST-EXP-01',
            'code_verification' => 'EXP123456789012345678901234567890',
            'student_dossier_id' => $dossier->id,
            'type' => 'attestation_inscription',
            'version' => 1,
            'statut' => 'valide',
            'storage_path' => 'student-dossiers/generated/test.pdf',
            'sha256' => hash('sha256', 'dummy'),
            'contenu_hash' => hash('sha256', 'dummy'),
            'expires_at' => now()->subDay(),
        ]);

        $this->artisan(MarquerDocumentsExpires::class)
            ->expectsOutputToContain('Documents expirés :')
            ->assertSuccessful();

        $this->assertNotNull($docPerime->fresh());
    }

    public function test_commande_purger_orphelins_mode_simulation_et_execution(): void
    {
        // Créer un fichier orphelin physique non référencé en base
        Storage::disk('private')->put('student-dossiers/orphelin_test.pdf', 'Fichier non référencé');

        // Mode simulation par défaut (aucun --execute)
        $this->artisan(PurgerOrphelins::class)
            ->expectsOutputToContain('simulation (ajoutez --execute pour supprimer)')
            ->assertSuccessful();

        Storage::disk('private')->assertExists('student-dossiers/orphelin_test.pdf');

        // Mode exécution (--execute)
        $this->artisan(PurgerOrphelins::class, ['--execute' => true])
            ->expectsOutputToContain('supprimés.')
            ->assertSuccessful();

        Storage::disk('private')->assertMissing('student-dossiers/orphelin_test.pdf');
    }

    // ────────────────────────────────────────────────────────────
    // 7. POLITIQUE DE RÉTENTION SANS DÉCISION ARBITRAIRE
    // ────────────────────────────────────────────────────────────

    public function test_politique_de_retention_documentee_sans_invention_arbitraire(): void
    {
        $durees = config('retention.durees');

        // Les durées légales sont explicitement null tant qu'une décision métier n'est pas actée
        $this->assertNull($durees['preuve_academique']);
        $this->assertNull($durees['preuve_financiere']);
        $this->assertNull($durees['donnee_personnelle_sensible']);
        $this->assertNull($durees['document_operationnel']);

        // Les classes de ressources documentaires sont répertoriées avec précision
        $ressources = config('retention.ressources');
        $this->assertArrayHasKey('etudiants', $ressources);
        $this->assertArrayHasKey('inscriptions_academiques', $ressources);
        $this->assertArrayHasKey('student_documents', $ressources);
        $this->assertArrayHasKey('recus_paiement', $ressources);
    }
}
