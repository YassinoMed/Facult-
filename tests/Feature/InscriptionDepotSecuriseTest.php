<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Modules\StudentDossiers\Contracts\AntivirusResult;
use App\Modules\StudentDossiers\Contracts\AntivirusScannerInterface;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Modules\StudentDossiers\Services\StudentDossierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * LOOP 05 : Dépôt de documents sécurisé et validation technique.
 * Vérifie :
 * - Le rejet des fichiers exécutables et extensions interdites ;
 * - Le rejet des contenus incohérents (MIME / signatures magiques) ;
 * - La limitation stricte de taille ;
 * - Le stockage privé exclusif (jamais public, nom aléatoire UUID, SHA-256) ;
 * - Le contrôle d'accès propriétaire (anti-IDOR entre étudiants) ;
 * - Le remplacement sans corruption d'historique (versionnage v1 -> v2) ;
 * - L'aperçu sécurisé en ligne (disposition inline + X-Content-Type-Options nosniff) ;
 * - Les retours JSON structurés pour le téléversement asynchrone ;
 * - Le hook antivirus : purge immédiate et rejet en cas de signature malveillante.
 */
class InscriptionDepotSecuriseTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculte;

    protected Etudiant $etudiantA;

    protected Etudiant $etudiantB;

    protected StudentDossier $dossierA;

    protected StudentDossier $dossierB;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.student-dossiers.active' => true]);
        Storage::fake('private');
        Storage::fake('public');

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FST',
            'description' => 'Sciences et technologies',
            'active' => true,
        ]);

        $this->etudiantA = $this->creerEtudiant('08111111', 'etudiant.a@univ.tn');
        $this->etudiantB = $this->creerEtudiant('08222222', 'etudiant.b@univ.tn');

        $service = app(StudentDossierService::class);
        $this->dossierA = $service->obtenirOuCreer($this->etudiantA);
        $this->dossierB = $service->obtenirOuCreer($this->etudiantB);
    }

    public function test_fichiers_non_autorises_extensions_dangereuses_rejetes(): void
    {
        session(['etudiant_auth_id' => $this->etudiantA->id]);

        foreach (['script.php', 'exploit.exe', 'code.js', 'page.html', 'vecteur.svg'] as $nom) {
            $fichier = UploadedFile::fake()->createWithContent($nom, '<?php phpinfo(); ?>');

            $reponse = $this->post(route('etudiant.dossier.documents.store'), [
                'document' => $fichier,
                'category' => 'identity',
                'document_type' => 'cin',
                'title' => 'Fichier dangereux',
            ]);

            $reponse->assertSessionHasErrors('document');
        }

        $this->assertSame(0, StudentDocument::count());
    }

    public function test_mime_incoherent_et_signature_magique_invalide_rejetes(): void
    {
        session(['etudiant_auth_id' => $this->etudiantA->id]);

        // Fichier nommé faux.pdf mais contenant des octets PNG
        $polyglotte = UploadedFile::fake()->createWithContent('faux.pdf', "\x89PNG\r\n\x1a\ncontenu_piege");

        $reponse = $this->post(route('etudiant.dossier.documents.store'), [
            'document' => $polyglotte,
            'category' => 'identity',
            'document_type' => 'cin',
            'title' => 'Faux PDF',
        ]);

        $reponse->assertSessionHasErrors('document');
        $this->assertSame(0, StudentDocument::count());
    }

    public function test_depassement_taille_maximale_rejete(): void
    {
        session(['etudiant_auth_id' => $this->etudiantA->id]);
        config(['student-dossiers.taille_max' => 1024]); // 1 Ko max pour le test

        $grosFichier = UploadedFile::fake()->createWithContent('trop_lourd.pdf', '%PDF-1.4 '.str_repeat('x', 2048));

        $reponse = $this->post(route('etudiant.dossier.documents.store'), [
            'document' => $grosFichier,
            'category' => 'identity',
            'document_type' => 'cin',
            'title' => 'Trop lourd',
        ]);

        $reponse->assertSessionHasErrors('document');
        $this->assertSame(0, StudentDocument::count());
    }

    public function test_nom_fichier_interne_aleatoire_et_stockage_strictement_prive(): void
    {
        session(['etudiant_auth_id' => $this->etudiantA->id]);

        $pdfValide = UploadedFile::fake()->createWithContent('cin_originale_candidat.pdf', "%PDF-1.4\nDonnees officielles\n%%EOF");

        $reponse = $this->post(route('etudiant.dossier.documents.store'), [
            'document' => $pdfValide,
            'category' => 'identity',
            'document_type' => 'cin',
            'title' => 'Carte d’identité nationale',
        ]);

        $reponse->assertRedirect(route('etudiant.dossier.show'));
        $reponse->assertSessionHas('success');

        $document = StudentDocument::where('student_dossier_id', $this->dossierA->id)->firstOrFail();

        // 1. Stockage sur disque privé uniquement
        Storage::disk('private')->assertExists($document->storage_path);
        $this->assertFalse(Storage::disk('public')->exists($document->storage_path));

        // 2. Nom interne aléatoire (UUID) et chemin masqué
        $this->assertStringNotContainsString('cin_originale_candidat', $document->storage_path);
        $this->assertStringStartsWith('student-dossiers/', $document->storage_path);
        $this->assertSame('cin_originale_candidat.pdf', $document->original_filename);

        // 3. Empreinte SHA-256
        $this->assertSame(hash('sha256', "%PDF-1.4\nDonnees officielles\n%%EOF"), $document->sha256);
        $this->assertSame('pending', $document->status);
    }

    public function test_acces_anonyme_refuse_vers_telechargement_et_apercu(): void
    {
        // Création d'un document pour l'étudiant A
        $document = $this->deposerDocument($this->dossierA, 'cin', 'doc.pdf', '%PDF-1.4 test');

        // Sans session auth
        $this->get(route('etudiant.dossier.documents.download', $document))->assertRedirect();
        $this->get(route('etudiant.dossier.documents.preview', $document))->assertRedirect();
    }

    public function test_isolation_inter_etudiants_anti_idor(): void
    {
        // Document appartenant à l'étudiant B
        $documentB = $this->deposerDocument($this->dossierB, 'cin', 'cin_b.pdf', '%PDF-1.4 etudiant_b');

        // Session connectée en tant qu'étudiant A
        session(['etudiant_auth_id' => $this->etudiantA->id]);

        // Tentative de téléchargement du document de B -> 404
        $this->get(route('etudiant.dossier.documents.download', $documentB))->assertStatus(404);

        // Tentative d'aperçu du document de B -> 404
        $this->get(route('etudiant.dossier.documents.preview', $documentB))->assertStatus(404);

        // Tentative de remplacement du document de B -> 404
        $nouveauFichier = UploadedFile::fake()->createWithContent('remplacement.pdf', '%PDF-1.4 nouveau');
        $this->post(route('etudiant.dossier.documents.replace', $documentB), [
            'document' => $nouveauFichier,
        ])->assertStatus(404);
    }

    public function test_remplacement_incremente_version_et_conserve_historique(): void
    {
        session(['etudiant_auth_id' => $this->etudiantA->id]);

        $v1 = UploadedFile::fake()->createWithContent('v1.pdf', "%PDF-1.4\nversion 1\n%%EOF");
        $this->post(route('etudiant.dossier.documents.store'), [
            'document' => $v1,
            'category' => 'identity',
            'document_type' => 'cin',
            'title' => 'CIN Recto',
        ]);

        $document = StudentDocument::where('student_dossier_id', $this->dossierA->id)->firstOrFail();
        $this->assertSame(1, (int) $document->versions()->max('version'));
        $cheminV1 = $document->storage_path;

        // Remplacement par la version 2
        $v2 = UploadedFile::fake()->createWithContent('v2.png', "\x89PNG\r\n\x1a\nversion 2");
        $reponseReplace = $this->post(route('etudiant.dossier.documents.replace', $document), [
            'document' => $v2,
        ]);
        $reponseReplace->assertRedirect(route('etudiant.dossier.show'));

        $document->refresh();

        // Le document est maintenant en version 2 et statut réinitialisé à pending
        $this->assertSame(2, (int) $document->versions()->max('version'));
        $this->assertSame('pending', $document->status);
        $this->assertSame('v2.png', $document->original_filename);
        $this->assertSame('image/png', $document->mime_type);

        // L'historique v1 est conservé sur le disque et en base
        $version1 = $document->versions()->where('version', 1)->firstOrFail();
        $this->assertSame('v1.pdf', $version1->original_filename);
        $this->assertSame('application/pdf', $version1->mime_type);
        Storage::disk('private')->assertExists($cheminV1);
        Storage::disk('private')->assertExists($document->storage_path);
    }

    public function test_route_apercu_renvoie_disposition_inline_avec_nosniff(): void
    {
        session(['etudiant_auth_id' => $this->etudiantA->id]);

        $document = $this->deposerDocument($this->dossierA, 'cin', 'fiche.pdf', "%PDF-1.4\nmon apercu\n%%EOF");

        $reponse = $this->get(route('etudiant.dossier.documents.preview', $document));

        $reponse->assertStatus(200);
        $reponse->assertHeader('Content-Type', 'application/pdf');
        $reponse->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('inline', (string) $reponse->headers->get('Content-Disposition'));
    }

    public function test_upload_et_remplacement_via_api_json_repondent_json_structure(): void
    {
        session(['etudiant_auth_id' => $this->etudiantA->id]);

        $fichier = UploadedFile::fake()->createWithContent('releve.pdf', "%PDF-1.4\nreleve notes\n%%EOF");

        $reponseJson = $this->postJson(route('etudiant.dossier.documents.store'), [
            'document' => $fichier,
            'category' => 'academic',
            'document_type' => 'releve_notes',
            'title' => 'Relevé de notes officiel',
        ]);

        $reponseJson->assertStatus(200);
        $reponseJson->assertJsonPath('success', true);
        $reponseJson->assertJsonStructure([
            'success',
            'message',
            'document' => [
                'id',
                'title',
                'category',
                'document_type',
                'status',
                'version',
                'original_filename',
            ],
        ]);
    }

    public function test_hook_antivirus_rejette_et_purge_fichier_si_infecte(): void
    {
        session(['etudiant_auth_id' => $this->etudiantA->id]);

        // Simuler un scanner antivirus qui détecte un fichier malveillant
        $scannerInfecte = new class implements AntivirusScannerInterface
        {
            public function analyser(array $contexte): AntivirusResult
            {
                return new AntivirusResult(sain: false, signature: 'EICAR-Test-Signature');
            }
        };
        $this->app->instance(AntivirusScannerInterface::class, $scannerInfecte);

        $fichier = UploadedFile::fake()->createWithContent('malveillant.pdf', "%PDF-1.4\ncontenu_malicieux\n%%EOF");

        $reponse = $this->postJson(route('etudiant.dossier.documents.store'), [
            'document' => $fichier,
            'category' => 'identity',
            'document_type' => 'cin',
            'title' => 'CIN infectée',
        ]);

        $reponse->assertStatus(422);
        $reponse->assertJsonPath('success', false);
        $this->assertStringContainsString('EICAR-Test-Signature', (string) $reponse->json('message'));

        // Aucun document créé en base
        $this->assertSame(0, StudentDocument::count());

        // Aucun fichier résiduel sur le disque privé
        $this->assertEmpty(Storage::disk('private')->allFiles());
    }

    private function creerEtudiant(string $cin, string $email): Etudiant
    {
        return Etudiant::create([
            'cin' => $cin,
            'nom' => 'Nom'.$cin,
            'prenom' => 'Prenom'.$cin,
            'email' => $email,
            'telephone' => '+216 20 111 222',
            'date_naissance' => '2002-04-12',
            'lieu_naissance' => 'Tunis',
            'sexe' => 'M',
            'nationalite' => 'Tunisienne',
            'adresse' => 'Rue de l’Université',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2024',
            'mention_bac' => 'Bien',
            'annee_academique' => '2025-2026',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'statut' => 'nouveau',
            'type_inscription' => 'premiere',
            'password' => 'Etudiant@123',
        ]);
    }

    private function deposerDocument(
        StudentDossier $dossier,
        string $code,
        string $nomFichier,
        string $contenu,
    ): StudentDocument {
        $chemin = "student-dossiers/2025-2026/{$dossier->etudiant_id}/identity/".hash('sha256', $contenu).'.pdf';
        Storage::disk('private')->put($chemin, $contenu);

        $document = StudentDocument::create([
            'student_dossier_id' => $dossier->id,
            'category' => 'identity',
            'document_type' => $code,
            'title' => ucfirst($code),
            'original_filename' => $nomFichier,
            'storage_path' => $chemin,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => strlen($contenu),
            'sha256' => hash('sha256', $contenu),
            'status' => 'pending',
        ]);

        $document->versions()->create([
            'version' => 1,
            'original_filename' => $nomFichier,
            'storage_path' => $chemin,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => strlen($contenu),
            'sha256' => hash('sha256', $contenu),
        ]);

        return $document;
    }
}
