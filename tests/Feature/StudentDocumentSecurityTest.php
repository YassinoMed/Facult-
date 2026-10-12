<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Modules\StudentDossiers\Resources\StudentDocumentResource;
use App\Modules\StudentDossiers\Services\DocumentStorageService;
use App\Modules\StudentDossiers\Services\StudentDossierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sécurité upload/téléchargement (§5-7) : validation profonde serveur,
 * stockage privé, hash, chemins internes jamais exposés.
 */
class StudentDocumentSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected StudentDossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.student-dossiers.active' => true]);
        Storage::fake('private');

        $faculte = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FST',
            'description' => 'Sciences et technologies',
            'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité Test',
            'email' => 'scolarite.test@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $etudiant = Etudiant::create([
            'cin' => 'BK960001',
            'nom' => 'Test',
            'prenom' => 'Etudiant',
            'email' => 'bk960001@example.com',
            'telephone' => '+212600000000',
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
            'faculte_id' => $faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '12 Rue de la Liberté',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'mention_bac' => 'Bien',
            'password' => 'motdepasse1',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-BK960001'])->save();
        $etudiant->definirStatut('valide');

        $this->dossier = $this->app->make(StudentDossierService::class)->obtenirOuCreer($etudiant->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    protected function donnees(UploadedFile $fichier): array
    {
        return [
            'document' => $fichier,
            'category' => 'identity',
            'document_type' => 'cin',
            'title' => 'CIN recto',
        ];
    }

    public function test_extension_dangereuse_refusee(): void
    {
        foreach (['malicieux.php', 'script.js', 'page.html', 'image.svg', 'lanceur.exe'] as $nom) {
            $fichier = UploadedFile::fake()->createWithContent($nom, '<?php echo "evil"; ?>');

            $this->actingAs($this->admin)->post(
                route('admin.student-dossiers.documents.store', $this->dossier),
                $this->donnees($fichier)
            )->assertSessionHasErrors('document');
        }

        $this->assertSame(0, StudentDocument::count());
    }

    public function test_mime_incoherent_refuse_proprement(): void
    {
        // Extension .pdf mais contenu PNG : la signature trahit le polyglotte.
        $fichier = UploadedFile::fake()->createWithContent('faux.pdf', "\x89PNG\r\n\x1a\npiege");

        $this->actingAs($this->admin)->post(
            route('admin.student-dossiers.documents.store', $this->dossier),
            $this->donnees($fichier)
        )->assertSessionHasErrors('document');

        $this->assertSame(0, StudentDocument::count());
    }

    public function test_taille_maximale_appliquee(): void
    {
        config(['student-dossiers.taille_max' => 1024]);

        $fichier = UploadedFile::fake()->createWithContent('gros.pdf', '%PDF-1.4 '.str_repeat('x', 2048));

        $this->actingAs($this->admin)->post(
            route('admin.student-dossiers.documents.store', $this->dossier),
            $this->donnees($fichier)
        )->assertSessionHasErrors('document');

        $this->assertSame(0, StudentDocument::count());
    }

    public function test_categorie_et_type_invalides_refuses(): void
    {
        $fichier = UploadedFile::fake()->createWithContent('doc.pdf', '%PDF-1.4 test');

        $this->actingAs($this->admin)->post(
            route('admin.student-dossiers.documents.store', $this->dossier),
            [
                'document' => $fichier,
                'category' => 'pirate',
                'document_type' => 'cin',
                'title' => 'Test',
            ]
        )->assertSessionHasErrors('category');

        // Type incohérent avec la catégorie (cin n'existe pas en medical).
        $this->actingAs($this->admin)->post(
            route('admin.student-dossiers.documents.store', $this->dossier),
            [
                'document' => UploadedFile::fake()->createWithContent('doc.pdf', '%PDF-1.4 test'),
                'category' => 'medical',
                'document_type' => 'cin',
                'title' => 'Test',
            ]
        )->assertSessionHasErrors('document_type');
    }

    public function test_stockage_prive_nom_aleatoire_et_sha256(): void
    {
        $fichier = UploadedFile::fake()->createWithContent('macin.pdf', '%PDF-1.4 secret');

        $this->actingAs($this->admin)->post(
            route('admin.student-dossiers.documents.store', $this->dossier),
            $this->donnees($fichier)
        )->assertRedirect();

        $document = StudentDocument::firstOrFail();

        // Nom interne aléatoire : jamais le nom d'origine.
        $this->assertStringNotContainsString('macin', $document->storage_path);
        $this->assertStringStartsWith('student-dossiers/', $document->storage_path);
        $this->assertSame('macin.pdf', $document->original_filename);

        // SHA-256 réel du contenu.
        $this->assertSame(hash('sha256', '%PDF-1.4 secret'), $document->sha256);

        // Disque privé uniquement : rien sur le disque public.
        Storage::disk('private')->assertExists($document->storage_path);
        $this->assertFalse(Storage::disk('public')->exists($document->storage_path));

        // Chemin interne masqué (modèle + Resource).
        $this->assertArrayNotHasKey('storage_path', $document->toArray());
        $resource = (new StudentDocumentResource($document))
            ->toArray(request());
        $this->assertArrayNotHasKey('storage_path', $resource);
    }

    public function test_telechargement_fichier_manquant_404(): void
    {
        $document = StudentDocument::create([
            'student_dossier_id' => $this->dossier->id,
            'category' => 'identity',
            'document_type' => 'cin',
            'title' => 'Fantôme',
            'original_filename' => 'fantome.pdf',
            'storage_path' => 'student-dossiers/inexistant.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 10,
            'sha256' => hash('sha256', 'x'),
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin)->get(
            route('admin.student-dossiers.documents.download', [$this->dossier, $document])
        )->assertNotFound();
    }

    public function test_telechargement_dossier_parent_incoherent_404(): void
    {
        $fichier = UploadedFile::fake()->createWithContent('doc.pdf', '%PDF-1.4 test');
        $this->actingAs($this->admin)->post(
            route('admin.student-dossiers.documents.store', $this->dossier),
            $this->donnees($fichier)
        );
        $document = StudentDocument::firstOrFail();

        // Second étudiant + dossier : le document du dossier A demandé
        // via l'URL du dossier B = 404 (cohérence parent/enfant).
        $etudiantB = Etudiant::create([
            'cin' => 'BK960002',
            'nom' => 'Autre',
            'prenom' => 'Etudiant',
            'email' => 'bk960002@example.com',
            'telephone' => '+212600000000',
            'date_naissance' => '2004-03-15',
            'sexe' => 'F',
            'faculte_id' => $this->dossier->etudiant->faculte_id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '12 Rue de la Liberté',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'mention_bac' => 'Bien',
            'password' => 'motdepasse1',
        ]);
        $etudiantB->forceFill(['num_inscription' => 'INS-2026-BK960002'])->save();
        $etudiantB->definirStatut('valide');
        $dossierB = $this->app->make(StudentDossierService::class)->obtenirOuCreer($etudiantB->fresh());

        $this->actingAs($this->admin)->get(
            route('admin.student-dossiers.documents.download', [$dossierB->id, $document->id])
        )->assertNotFound();
    }

    public function test_nom_telechargement_securise(): void
    {
        $stockage = $this->app->make(DocumentStorageService::class);

        $this->assertSame('document.pdf', $stockage->nomTelechargementSecurise('/tmp/../../document.pdf'));
        $this->assertSame('doc.pdf', $stockage->nomTelechargementSecurise("doc\r\n.pdf"));
        $this->assertSame('document', $stockage->nomTelechargementSecurise('...'));
        // Chemin interne (répertoires UUID) : seul le basename survit.
        $this->assertSame(
            'uuid-xyz.pdf',
            $stockage->nomTelechargementSecurise('student-dossiers/2026/1/identity/uuid-xyz.pdf')
        );
    }

    public function test_invites_rediriges(): void
    {
        $this->get('/admin/student-dossiers')->assertRedirect(route('admin.connexion.show'));
        $this->get('/etudiant/dossier')->assertRedirect(route('etudiant.connexion.show'));
    }

    public function test_stockage_refuse_contenu_reel_non_image(): void
    {
        // Vrai UploadedFile (mode test) : finfo lit le CONTENU réel,
        // contrairement aux fakes dont getMimeType() devine depuis le nom.
        $chemin = tempnam(sys_get_temp_dir(), 'poly');
        file_put_contents($chemin, '<?php echo "evil"; ?>');
        $fichier = new UploadedFile($chemin, 'photo.jpg', null, null, true);

        try {
            $this->app->make(DocumentStorageService::class)->stocker(
                $fichier, $this->dossier->etudiant_id, '2026-2027', 'identity'
            );
            $this->fail('Un script renommé .jpg ne doit jamais être stocké.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('photo.jpg', $fichier->getClientOriginalName());
        } finally {
            @unlink($chemin);
        }

        $this->assertSame([], Storage::disk('private')->allFiles('student-dossiers'));
    }
}
