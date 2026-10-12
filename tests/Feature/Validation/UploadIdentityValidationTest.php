<?php

namespace Tests\Feature\Validation;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Services\Identity\IdentityDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Tests unitaires de la validation profonde des uploads d'identité (§14).
 * Couvre l'anti-polyglot, la validation MIME réelle, et les limites.
 */
class UploadIdentityValidationTest extends TestCase
{
    use RefreshDatabase;

    private IdentityDocumentService $service;

    private Etudiant $etudiant;

    private const REPERTOIRE_TEMPORAIRE = 'identity-scan/tmp';

    protected function setUp(): void
    {
        parent::setUp();

        $faculte = Faculte::create(['nom' => 'FS', 'code' => 'FS', 'active' => true]);

        $programme = Programme::create([
            'nom' => 'Licence Info', 'code' => 'L-INFO', 'faculte_id' => $faculte->id, 'actif' => true,
        ]);

        $niveau = Niveau::create([
            'programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'Licence 1', 'ordre' => 1,
        ]);

        $this->etudiant = Etudiant::create([
            'cin' => '01020304', 'nom' => 'Test', 'prenom' => 'Upload',
            'email' => uniqid('upload.', true).'@test.tn',
            'sexe' => 'M', 'faculte_id' => $faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'annee_universitaire_id' => 1,
            'adresse' => 'Adr', 'ville' => 'Tunis', 'code_postal' => '1000',
            'annee_bac' => 2024, 'password' => 'secret', 'statut' => 'actif',
        ]);

        $this->service = app(IdentityDocumentService::class);
    }

    /**
     * Crée un fichier temporaire avec du contenu.
     */
    private function creerFichierTemporaire(string $contenu, string $nomFichier): UploadedFile
    {
        $tmpPath = tempnam(storage_path('app/private/'), 'upload_test_');
        file_put_contents($tmpPath, $contenu);

        return new UploadedFile(
            $tmpPath,
            $nomFichier,
            mime_content_type($tmpPath) ?: 'application/octet-stream',
            null,
            true
        );
    }

    /**
     * ✅ PDF valide accepté
     */
    public function test_upload_pdf_valide_accepte(): void
    {
        $fichier = UploadedFile::fake()->createWithContent(
            'piece.pdf', "%PDF-1.4\ncontenu factice\n", 'application/pdf'
        );

        $chemin = $this->service->uploadDocument($fichier, 'cin');

        $this->assertStringStartsWith(self::REPERTOIRE_TEMPORAIRE.'/', $chemin);
        $this->assertStringEndsWith('.pdf', $chemin);
        $this->assertTrue(Storage::disk('local')->exists($chemin));

        Storage::disk('local')->delete($chemin);
    }

    /**
     * ✅ Image JPEG valide acceptée (via image() Laravel)
     */
    public function test_upload_jpeg_valide_accepte(): void
    {
        // La méthode image() de Laravel crée une vraie image JPEG valide
        $fichier = UploadedFile::fake()->image('photo.jpg', 200, 200)->size(100);

        // Remplacer le MIME par JPEG car fake() utilise par défaut 'image/jpeg'
        $tmpPath = $fichier->getRealPath();

        $fichierReel = new UploadedFile(
            $tmpPath,
            'photo.jpg',
            'image/jpeg',
            null,
            true
        );

        $chemin = $this->service->uploadDocument($fichierReel, 'cin');

        $this->assertStringStartsWith(self::REPERTOIRE_TEMPORAIRE.'/', $chemin);
        $this->assertStringEndsWith('.jpg', $chemin);

        Storage::disk('local')->delete($chemin);
    }

    /**
     * ❌ Extension trompeuse: PDF avec contenu image
     */
    public function test_upload_extension_trompeuse_refusee(): void
    {
        $fichier = UploadedFile::fake()->createWithContent(
            'trompeur.pdf', "\xFF\xD8\xFFcontenu jpeg", 'image/jpeg'
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/corrompu ou invalide/');

        $this->service->uploadDocument($fichier, 'cin');
    }

    /**
     * ❌ Polyglot: Image avec contenu PHP (via flux fichier créé)
     */
    public function test_upload_polyglot_refuse(): void
    {
        $contenuPolyglot = 'GIF89a<?php system($_GET["cmd"]); ?>';
        $tmpPath = tempnam(storage_path('app/private/'), 'polyglot_test_');
        file_put_contents($tmpPath, $contenuPolyglot);

        $fichier = new UploadedFile(
            $tmpPath,
            'malice.jpg',
            'image/jpeg',
            null,
            true
        );

        $this->expectException(ValidationException::class);

        try {
            $this->service->uploadDocument($fichier, 'cin');
        } finally {
            unlink($tmpPath);
        }
    }

    /**
     * ❌ Extension non autorisée
     */
    public function test_upload_extension_non_autorisee_refusee(): void
    {
        $fichier = UploadedFile::fake()->createWithContent(
            'malicieux.exe', 'MZ', 'application/x-dosexec'
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/Format non autorisé/');

        $this->service->uploadDocument($fichier, 'cin');
    }

    /**
     * ❌ Taille trop grande
     */
    public function test_upload_fichier_trop_grand_refuse(): void
    {
        $tailleMax = (int) config('identity.upload.taille_max');
        $fichier = UploadedFile::fake()->createWithContent(
            'gros.pdf', str_repeat('x', $tailleMax + 1000), 'application/pdf'
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/dépasse la taille maximale/');

        $this->service->uploadDocument($fichier, 'cin');
    }

    /**
     * ❌ PDF corrompu (pas la signature %PDF-)
     */
    public function test_upload_pdf_corrompu_refuse(): void
    {
        $fichier = UploadedFile::fake()->createWithContent(
            'corrompu.pdf', 'PAS_LA_SIGNATURE_PDF', 'application/pdf'
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/corrompu ou invalide/');

        $this->service->uploadDocument($fichier, 'cin');
    }

    /**
     * ❌ Image corrompue (non décodable) (via flux fichier créé)
     */
    public function test_upload_image_corrompue_refuse(): void
    {
        $tmpPath = tempnam(storage_path('app/private/'), 'img_corrupt_test_');
        file_put_contents($tmpPath, 'Texte aléatoire qui n\'est pas une image');

        $fichier = new UploadedFile(
            $tmpPath,
            'corrompue.jpg',
            'image/jpeg',
            null,
            true
        );

        $this->expectException(ValidationException::class);

        try {
            $this->service->uploadDocument($fichier, 'cin');
        } finally {
            unlink($tmpPath);
        }
    }

    /**
     * ✅ Nom fichier path traversal sécurisé
     */
    public function test_upload_nom_traversal_refuse(): void
    {
        $contenu = "%PDF-1.4\ncontenu factice\n";
        $fichier = UploadedFile::fake()->createWithContent(
            '../../../etc/passwd.pdf', $contenu, 'application/pdf'
        );

        $chemin = $this->service->uploadDocument($fichier, 'cin');

        $this->assertStringNotContainsString('../', $chemin);
        $this->assertStringStartsWith(self::REPERTOIRE_TEMPORAIRE.'/', $chemin);

        Storage::disk('local')->delete($chemin);
    }

    /**
     * ✅ Type document accepté par le service (validation externe)
     */
    public function test_upload_type_document_via_service(): void
    {
        $fichier = UploadedFile::fake()->createWithContent(
            'doc.pdf', "%PDF-1.4\ntest\n", 'application/pdf'
        );

        // Le service ne valide pas le type, on juste vérifie qu'il fonctionne
        $chemin = $this->service->uploadDocument($fichier, 'cin');

        $this->assertStringStartsWith(self::REPERTOIRE_TEMPORAIRE.'/', $chemin);
        Storage::disk('local')->delete($chemin);
    }
}
