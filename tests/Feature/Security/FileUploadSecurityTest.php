<?php

namespace Tests\Feature\Security;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\StudentDossiers\Services\DocumentStorageService;
use App\Services\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Sécurité des uploads (§16-20, §53) : extension + MIME réel + signature,
 * cohérence, noms assainis, anti traversal.
 */
class FileUploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Etudiant $etudiant;

    private int $auteurId;

    protected function setUp(): void
    {
        parent::setUp();

        $faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->etudiant = Etudiant::create([
            'cin' => 'BK123456', 'nom' => 'Doc', 'prenom' => 'Test',
            'email' => uniqid('doc.', true).'@example.com',
            'sexe' => 'M', 'faculte_id' => $faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026',
            'adresse' => '1 rue', 'ville' => 'Rabat',
            'code_postal' => '10000', 'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ]);
        $this->auteurId = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => uniqid('sc.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite')->id;
    }

    public function test_pdf_valide_accepte(): void
    {
        $fichier = UploadedFile::fake()->createWithContent(
            'releve.pdf', "%PDF-1.4\ncontenu factice\n", 'application/pdf'
        );

        $document = app(DocumentService::class)->stocker(
            $this->etudiant, null, $fichier, 'releve_notes', $this->auteurId
        );

        $this->assertStringStartsWith('archives/etudiant_', $document->chemin);
        $this->assertMatchesRegularExpression('/\.pdf$/', $document->chemin);
    }

    public function test_jpeg_renomme_pdf_refuse(): void
    {
        // Polyglotte : contenu JPEG réel avec extension .pdf.
        $fichier = UploadedFile::fake()->createWithContent(
            'faux.pdf', "\xFF\xD8\xFFcontenu jpeg", 'image/jpeg'
        );

        $this->expectException(ValidationException::class);

        app(DocumentService::class)->stocker($this->etudiant, null, $fichier, 'autre', $this->auteurId);
    }

    public function test_script_php_refuse(): void
    {
        foreach (['malicieux.php', 'malicieux.phtml', 'malicieux.phar', 'script.php.jpg'] as $nom) {
            $fichier = UploadedFile::fake()->createWithContent(
                $nom, '<?php system($_GET["cmd"]); ?>', 'application/x-php'
            );

            try {
                app(DocumentService::class)->stocker($this->etudiant, null, $fichier, 'autre', $this->auteurId);
                $this->fail("Fichier {$nom} aurait dû être refusé.");
            } catch (ValidationException) {
                // Attendu.
            }
        }

        $this->assertTrue(true);
    }

    public function test_double_extension_avec_contenu_php_refusee(): void
    {
        $fichier = UploadedFile::fake()->createWithContent(
            'doc.pdf', '<?php echo "pirate"; ?>', 'text/plain'
        );

        $this->expectException(ValidationException::class);

        app(DocumentService::class)->stocker($this->etudiant, null, $fichier, 'autre', $this->auteurId);
    }

    public function test_nom_telechargement_assaini_anti_header_injection(): void
    {
        $service = app(DocumentService::class);

        $this->assertSame('passwd', $service->nomTelechargementSecurise("../../etc/passwd\r\n"));
        $this->assertStringNotContainsString("\r", $service->nomTelechargementSecurise("evil\"\r\nSet-Cookie: x.pdf"));
        $this->assertStringNotContainsString('"', $service->nomTelechargementSecurise('evil".pdf'));
    }

    public function test_chemin_dossiers_blanchi_anti_traversal(): void
    {
        $fichier = UploadedFile::fake()->createWithContent(
            'doc.pdf', "%PDF-1.4\ncontenu\n", 'application/pdf'
        );

        $meta = app(DocumentStorageService::class)->stocker(
            $fichier, $this->etudiant->id, '../../evil', '../traversal'
        );

        $this->assertStringNotContainsString('..', $meta['storage_path']);
        $this->assertStringStartsWith('student-dossiers/sans-annee/', $meta['storage_path']);
    }

    public function test_upload_via_http_exige_authentification(): void
    {
        $fichier = UploadedFile::fake()->createWithContent('doc.pdf', "%PDF-1.4\nx\n", 'application/pdf');

        // Sans session back-office : redirection vers la connexion.
        $this->post(route('admin.archives.documents.store', 1), [
            'document' => $fichier, 'type_document' => 'autre',
        ])->assertRedirect();
    }
}
