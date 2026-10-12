<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\User;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Modules\StudentDossiers\Services\StudentDossierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cycle de vie des documents (§2-3/§9) : upload, versionnage,
 * validation/rejet, suppression, téléchargement, audit.
 */
class StudentDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculte;

    protected User $admin;

    protected Etudiant $etudiant;

    protected StudentDossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.student-dossiers.active' => true]);
        Storage::fake('private');

        $this->faculte = Faculte::create([
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

        $this->etudiant = Etudiant::create([
            'cin' => 'BK950001',
            'nom' => 'Test',
            'prenom' => 'Etudiant',
            'email' => 'bk950001@example.com',
            'telephone' => '+212600000000',
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '12 Rue de la Liberté',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'mention_bac' => 'Bien',
            'password' => 'motdepasse1',
        ]);
        $this->etudiant->forceFill(['num_inscription' => 'INS-2026-BK950001'])->save();
        $this->etudiant->definirStatut('valide');
        $this->etudiant = $this->etudiant->fresh();

        $this->dossier = $this->app->make(StudentDossierService::class)->obtenirOuCreer($this->etudiant);
    }

    public static function fauxPdf(string $nom = 'document.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nom, "%PDF-1.4\ncontenu factice\n%%EOF");
    }

    public static function fausseImage(string $nom = 'photo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nom, "\x89PNG\r\n\x1a\ncontenu factice");
    }

    /**
     * @return array<string, mixed>
     */
    protected function donnees(string $type = 'cin'): array
    {
        return [
            'category' => 'identity',
            'document_type' => $type,
            'title' => 'CIN recto',
            'description' => 'Pièce d identité',
        ];
    }

    public function test_upload_pdf_cree_document_version_et_audit(): void
    {
        $reponse = $this->actingAs($this->admin)->post(
            route('admin.student-dossiers.documents.store', $this->dossier),
            $this->donnees() + ['document' => self::fauxPdf()]
        );

        $reponse->assertRedirect(route('admin.student-dossiers.show', $this->dossier));

        $document = StudentDocument::firstOrFail();
        $this->assertSame('pending', $document->status);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertSame(64, strlen($document->sha256));
        $this->assertSame(1, $document->versions()->count());
        Storage::disk('private')->assertExists($document->storage_path);

        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $this->etudiant->id,
            'action' => 'student_document.uploaded',
        ]);
    }

    public function test_upload_image_acceptee(): void
    {
        $this->actingAs($this->admin)->post(
            route('admin.student-dossiers.documents.store', $this->dossier),
            $this->donnees() + ['document' => self::fausseImage()]
        )->assertRedirect();

        $this->assertSame('image/png', StudentDocument::firstOrFail()->mime_type);
    }

    public function test_remplacement_versionne_sans_ecraser(): void
    {
        $this->actingAs($this->admin)->post(
            route('admin.student-dossiers.documents.store', $this->dossier),
            $this->donnees() + ['document' => self::fauxPdf('v1.pdf')]
        );

        $document = StudentDocument::firstOrFail();
        $ancienChemin = $document->storage_path;

        $this->actingAs($this->admin)->post(
            route('admin.student-dossiers.documents.replace', $document),
            ['document' => self::fauxPdf('v2.pdf')]
        )->assertRedirect();

        $document->refresh();
        $this->assertSame(2, $document->versions()->count());
        $this->assertSame('pending', $document->status);
        // L'ancien fichier est conservé dans l'historique.
        Storage::disk('private')->assertExists($ancienChemin);
        Storage::disk('private')->assertExists($document->storage_path);
        $this->assertNotSame($ancienChemin, $document->storage_path);

        $this->assertDatabaseHas('historique_academique', [
            'action' => 'student_document.replaced',
        ]);
    }

    public function test_validation_et_rejet_avec_motif(): void
    {
        $this->actingAs($this->admin)->post(
            route('admin.student-dossiers.documents.store', $this->dossier),
            $this->donnees() + ['document' => self::fauxPdf()]
        );
        $document = StudentDocument::firstOrFail();

        $this->actingAs($this->admin)
            ->patch(route('admin.student-dossiers.documents.validate', $document))
            ->assertRedirect();
        $this->assertSame('validated', $document->fresh()->status);
        $this->assertNotNull($document->fresh()->validated_at);

        $this->actingAs($this->admin)
            ->patch(route('admin.student-dossiers.documents.reject', $document), [])
            ->assertSessionHasErrors('motif');

        $this->actingAs($this->admin)
            ->patch(route('admin.student-dossiers.documents.reject', $document), ['motif' => 'Document illisible, renvoyer un scan net.'])
            ->assertRedirect();
        $document->refresh();
        $this->assertSame('rejected', $document->status);
        $this->assertSame('Document illisible, renvoyer un scan net.', $document->rejected_reason);
    }

    public function test_suppression_nettoie_fichiers_et_lignes(): void
    {
        $this->actingAs($this->admin)->post(
            route('admin.student-dossiers.documents.store', $this->dossier),
            $this->donnees() + ['document' => self::fauxPdf()]
        );
        $document = StudentDocument::firstOrFail();
        $chemin = $document->storage_path;

        $this->actingAs($this->admin)
            ->delete(route('admin.student-dossiers.documents.destroy', $document))
            ->assertRedirect();

        $this->assertDatabaseMissing('student_documents', ['id' => $document->id]);
        $this->assertDatabaseMissing('student_document_versions', ['student_document_id' => $document->id]);
        Storage::disk('private')->assertMissing($chemin);
        $this->assertDatabaseHas('historique_academique', ['action' => 'student_document.deleted']);
    }

    public function test_telechargement_admin_audite_sans_exposer_le_chemin(): void
    {
        $this->actingAs($this->admin)->post(
            route('admin.student-dossiers.documents.store', $this->dossier),
            $this->donnees() + ['document' => self::fauxPdf()]
        );
        $document = StudentDocument::firstOrFail();

        $reponse = $this->actingAs($this->admin)->get(
            route('admin.student-dossiers.documents.download', [$this->dossier, $document])
        );

        $reponse->assertOk();
        $this->assertStringNotContainsString($document->storage_path, $reponse->streamedContent());
        $this->assertDatabaseHas('historique_academique', [
            'action' => 'student_document.downloaded',
            'entite_id' => $document->id,
        ]);
    }

    public function test_etudiant_upload_et_telecharge_son_dossier(): void
    {
        session(['etudiant_auth_id' => $this->etudiant->id]);

        $this->post(route('etudiant.dossier.documents.store'), $this->donnees() + ['document' => self::fauxPdf()])
            ->assertRedirect(route('etudiant.dossier.show'));

        $document = StudentDocument::firstOrFail();

        $this->get(route('etudiant.dossier.show'))->assertOk()->assertSee('CIN recto');

        $this->get(route('etudiant.dossier.documents.download', $document))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_mime_persiste_determine_content_type(): void
    {
        $cas = [
            'application/pdf' => UploadedFile::fake()->createWithContent('doc.pdf', '%PDF-1.4 test'),
            'image/png' => UploadedFile::fake()->createWithContent('img.png', "\x89PNG\r\n\x1a\ntest"),
            'image/jpeg' => UploadedFile::fake()->createWithContent('img.jpg', "\xFF\xD8\xFF\xE0test"),
            'image/webp' => UploadedFile::fake()->createWithContent('img.webp', "RIFF\x10\x00\x00\x00WEBPtest"),
        ];

        foreach ($cas as $mimeAttendu => $fichier) {
            $titre = 'Doc '.$mimeAttendu;

            $this->actingAs($this->admin)->post(
                route('admin.student-dossiers.documents.store', $this->dossier),
                array_merge($this->donnees(), ['document' => $fichier, 'title' => $titre])
            )->assertRedirect();

            // Règle métier : DB MIME == HTTP Content-Type.
            $document = StudentDocument::where('title', $titre)->firstOrFail();
            $this->assertSame($mimeAttendu, $document->mime_type);

            $this->actingAs($this->admin)->get(
                route('admin.student-dossiers.documents.download', [$this->dossier, $document])
            )->assertOk()
                ->assertHeader('Content-Type', $mimeAttendu)
                ->assertHeader('X-Content-Type-Options', 'nosniff');
        }
    }

    public function test_content_type_ignore_la_detection_disque(): void
    {
        // Contenu PNG réel sur disque, MIME persisté PDF : la réponse
        // suit la DB — preuve d'indépendance au driver de filesystem.
        Storage::disk('private')->put('student-dossiers/trompeur.bin', "\x89PNG\r\n\x1a\npiege");
        $document = StudentDocument::create([
            'student_dossier_id' => $this->dossier->id,
            'category' => 'identity',
            'document_type' => 'cin',
            'title' => 'Trompeur',
            'original_filename' => 'trompeur.pdf',
            'storage_path' => 'student-dossiers/trompeur.bin',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 20,
            'sha256' => hash('sha256', 'trompeur'),
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin)->get(
            route('admin.student-dossiers.documents.download', [$this->dossier, $document])
        )->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_telechargement_version_mime_propre(): void
    {
        $this->actingAs($this->admin)->post(
            route('admin.student-dossiers.documents.store', $this->dossier),
            $this->donnees() + ['document' => self::fauxPdf('v1.pdf')]
        );
        $document = StudentDocument::firstOrFail();

        $this->actingAs($this->admin)->post(
            route('admin.student-dossiers.documents.replace', $document),
            ['document' => UploadedFile::fake()->createWithContent('v2.png', "\x89PNG\r\n\x1a\nv2")]
        )->assertRedirect();

        $document->refresh();
        $version1 = $document->versions()->where('version', 1)->firstOrFail();

        // Document courant (v2 PNG) et version 1 (PDF) : MIME propres.
        $this->actingAs($this->admin)->get(
            route('admin.student-dossiers.documents.download', [$this->dossier, $document])
        )->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->actingAs($this->admin)->get(
            route('admin.student-dossiers.documents.versions.download', [$this->dossier, $document, $version1])
        )->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // Espace étudiant : version accessible au propriétaire uniquement.
        session(['etudiant_auth_id' => $this->etudiant->id]);
        $this->get(route('etudiant.dossier.documents.versions.download', [$document, $version1]))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_journal_sans_secret_ni_contenu(): void
    {
        $this->actingAs($this->admin)->post(
            route('admin.student-dossiers.documents.store', $this->dossier),
            $this->donnees() + ['document' => self::fauxPdf()]
        );

        $entrees = HistoriqueAcademique::where('action', 'like', 'student_document.%')->get();
        $this->assertNotEmpty($entrees);

        foreach ($entrees as $entree) {
            $contenu = json_encode([$entree->ancienne_valeur, $entree->nouvelle_valeur]);
            $this->assertStringNotContainsString('motdepasse1', $contenu);
            $this->assertStringNotContainsString('%PDF-1.4', $contenu);
        }
    }
}
