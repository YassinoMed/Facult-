<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\StudentDossiers\Jobs\GenerateStudentDossierPdfJob;
use App\Modules\StudentDossiers\Models\GeneratedDocument;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Modules\StudentDossiers\Services\Pdf\StudentDossierPdfService;
use App\Modules\StudentDossiers\Services\StudentDossierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Génération PDF du dossier (§21) : référence, A4, UTF-8, preview,
 * téléchargement, impression, audit, SHA-256, stockage privé.
 */
class StudentDossierPdfTest extends TestCase
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
            'cin' => 'BK980001',
            'nom' => 'Bennani Élève',
            'prenom' => 'Salma',
            'email' => 'bk980001@example.com',
            'telephone' => '+212600000000',
            'date_naissance' => '2004-03-15',
            'sexe' => 'F',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '12 Rue de la Liberté',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'mention_bac' => 'Très Bien',
            'password' => 'motdepasse1',
        ]);
        $this->etudiant->forceFill(['num_inscription' => 'INS-2026-BK980001'])->save();
        $this->etudiant->definirStatut('valide');
        $this->etudiant = $this->etudiant->fresh();

        $this->dossier = $this->app->make(StudentDossierService::class)->obtenirOuCreer($this->etudiant);
    }

    public function test_module_desactive_pdf_404(): void
    {
        config(['modules.student-dossiers.active' => false]);

        $this->actingAs($this->admin)->post(
            route('admin.student-dossiers.pdf.generer', $this->dossier)
        )->assertNotFound();
        $this->actingAs($this->admin)->get(
            route('admin.student-dossiers.pdf.preview', $this->dossier)
        )->assertNotFound();

        session(['etudiant_auth_id' => $this->etudiant->id]);
        $this->get(route('etudiant.dossier.pdf.preview'))->assertNotFound();
    }

    public function test_generer_cree_pdf_reference_unique_sha_prive(): void
    {
        $reponse = $this->actingAs($this->admin)->post(
            route('admin.student-dossiers.pdf.generer', $this->dossier)
        );

        $reponse->assertRedirect(route('admin.student-dossiers.pdf.preview', $this->dossier));

        $document = GeneratedDocument::firstOrFail();
        $this->assertStringStartsWith('PDF-'.$this->dossier->reference, $document->reference);
        $this->assertSame(64, strlen($document->sha256));
        $this->assertSame(64, strlen($document->contenu_hash));
        Storage::disk('private')->assertExists($document->storage_path);
        $this->assertFalse(Storage::disk('public')->exists($document->storage_path));

        $this->assertDatabaseHas('historique_academique', [
            'action' => 'student_dossier.pdf.generated',
            'entite_id' => $document->id,
        ]);
    }

    public function test_pas_de_regeneration_si_inchange(): void
    {
        $service = $this->app->make(StudentDossierPdfService::class);
        $premier = $service->demander($this->dossier, $this->admin->id);
        $second = $service->demander($this->dossier, $this->admin->id);

        $this->assertSame('pret', $premier['statut']);
        $this->assertSame('pret', $second['statut']);
        $this->assertSame($premier['document']->id, $second['document']->id);
        $this->assertSame(1, GeneratedDocument::count());
    }

    public function test_preview_stream_download_print(): void
    {
        $this->actingAs($this->admin)->post(route('admin.student-dossiers.pdf.generer', $this->dossier));
        $document = GeneratedDocument::firstOrFail();

        $this->actingAs($this->admin)->get(route('admin.student-dossiers.pdf.preview', $this->dossier))
            ->assertOk()->assertSee('apercu-pdf')->assertSee($document->reference);

        $this->actingAs($this->admin)->get(route('admin.student-dossiers.pdf.fichier', $this->dossier))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $fichierHeaders = $this->actingAs($this->admin)->get(route('admin.student-dossiers.pdf.fichier', $this->dossier))->headers;
        $this->assertStringStartsWith('inline', $fichierHeaders->get('Content-Disposition'));

        $telechargement = $this->actingAs($this->admin)->get(
            route('admin.student-dossiers.pdf.download', $this->dossier)
        );
        $telechargement->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment', $telechargement->headers->get('Content-Disposition'));
        $this->assertTrue(str_starts_with($telechargement->streamedContent(), '%PDF'));

        $this->actingAs($this->admin)->get(route('admin.student-dossiers.pdf.print', $this->dossier))
            ->assertRedirect(route('admin.student-dossiers.pdf.fichier', $this->dossier));

        foreach (['previewed', 'downloaded', 'printed'] as $action) {
            $this->assertDatabaseHas('historique_academique', [
                'action' => "student_dossier.pdf.{$action}",
            ]);
        }
    }

    public function test_contenu_html_a4_footer_sans_secrets(): void
    {
        $service = $this->app->make(StudentDossierPdfService::class);
        $html = view('modules.student-dossiers.pdf.dossier', $service->donnees($this->dossier))->render();

        // A4, pagination, footer, référence, UTF-8 français.
        $this->assertStringContainsString('A4', $html);
        $this->assertStringContainsString('counter(page)', $html);
        $this->assertStringContainsString('counter(pages)', $html);
        $this->assertStringContainsString($this->dossier->reference, $html);
        $this->assertStringContainsString('Bennani Élève', $html);
        $this->assertStringContainsString('BK980001', $html);

        // Aucun secret, aucun chemin interne.
        foreach (['motdepasse1', '$2y$', 'remember_token', 'storage_path', 'storage/app', 'APP_KEY'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $html);
        }
    }

    public function test_arabe_ne_casse_pas_la_generation(): void
    {
        $this->etudiant->forceFill(['nom' => 'بوناب', 'prenom' => 'محمد ياسين'])->save();

        $this->actingAs($this->admin)->post(route('admin.student-dossiers.pdf.generer', $this->dossier))
            ->assertRedirect();

        $document = GeneratedDocument::firstOrFail();
        Storage::disk('private')->assertExists($document->storage_path);
    }

    public function test_pdf_avec_documents_statuts_et_versions(): void
    {
        foreach (['validated', 'pending', 'rejected'] as $i => $statut) {
            $this->actingAs($this->admin)->post(
                route('admin.student-dossiers.documents.store', $this->dossier),
                [
                    'document' => UploadedFile::fake()->createWithContent("doc{$i}.pdf", '%PDF-1.4 test'),
                    'category' => 'identity',
                    'document_type' => 'cin',
                    'title' => "Pièce {$statut}",
                ]
            );
        }
        $document = StudentDocument::where('title', 'Pièce validated')->firstOrFail();
        $this->actingAs($this->admin)->patch(route('admin.student-dossiers.documents.validate', $document));
        $rejete = StudentDocument::where('title', 'Pièce rejected')->firstOrFail();
        $this->actingAs($this->admin)->patch(
            route('admin.student-dossiers.documents.reject', $rejete),
            ['motif' => 'Illisible.']
        );

        $service = $this->app->make(StudentDossierPdfService::class);
        $html = view('modules.student-dossiers.pdf.dossier', $service->donnees($this->dossier->fresh()))->render();

        $this->assertStringContainsString('Pièce validated', $html);
        $this->assertStringContainsString('validated', $html);
        $this->assertStringContainsString('pending', $html);
        $this->assertStringContainsString('rejected', $html);
    }

    public function test_etudiant_preview_download_print(): void
    {
        session(['etudiant_auth_id' => $this->etudiant->id]);

        $this->get(route('etudiant.dossier.pdf.preview'))
            ->assertOk()->assertSee('apercu-pdf', false);

        $this->get(route('etudiant.dossier.pdf.download'))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->get(route('etudiant.dossier.pdf.print'))
            ->assertRedirect(route('etudiant.dossier.pdf.fichier'));

        $this->assertDatabaseHas('historique_academique', ['action' => 'student_dossier.pdf.downloaded']);
    }

    public function test_admin_voit_boutons_selon_etat(): void
    {
        // Sans PDF : Générer uniquement.
        $this->actingAs($this->admin)->get(route('admin.student-dossiers.show', $this->dossier))
            ->assertOk()
            ->assertSee('Générer PDF')
            ->assertDontSee('Régénérer');

        $this->actingAs($this->admin)->post(route('admin.student-dossiers.pdf.generer', $this->dossier));

        // Avec PDF : Prévisualiser / Télécharger / Imprimer / Régénérer + référence.
        $document = GeneratedDocument::firstOrFail();
        $this->actingAs($this->admin)->get(route('admin.student-dossiers.show', $this->dossier))
            ->assertOk()
            ->assertSee('Prévisualiser')
            ->assertSee('Télécharger PDF')
            ->assertSee('Imprimer')
            ->assertSee('Régénérer')
            ->assertSee($document->reference);
    }

    public function test_double_soumission_idempotente_et_regeneration_forcee(): void
    {
        $this->actingAs($this->admin)->post(route('admin.student-dossiers.pdf.generer', $this->dossier));
        $this->actingAs($this->admin)->post(route('admin.student-dossiers.pdf.generer', $this->dossier));

        // Double clic : aucun doublon (empreinte identique).
        $this->assertSame(1, GeneratedDocument::count());

        // Régénération explicite : nouvelle version voulue.
        $this->actingAs($this->admin)->post(
            route('admin.student-dossiers.pdf.generer', $this->dossier),
            ['force' => '1']
        )->assertRedirect();

        $this->assertSame(2, GeneratedDocument::count());
        $this->assertSame(2, GeneratedDocument::latest('id')->first()->version);
    }

    public function test_etudiant_voit_boutons_et_genere(): void
    {
        session(['etudiant_auth_id' => $this->etudiant->id]);

        $this->get(route('etudiant.dossier.show'))
            ->assertOk()
            ->assertSee('Générer mon dossier PDF');

        $this->post(route('etudiant.dossier.pdf.generer'))
            ->assertRedirect(route('etudiant.dossier.pdf.preview'));

        $document = GeneratedDocument::firstOrFail();

        $this->get(route('etudiant.dossier.show'))
            ->assertOk()
            ->assertSee('Prévisualiser')
            ->assertSee($document->reference);

        $this->get(route('etudiant.dossier.pdf.download'))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_job_regenere_en_arriere_plan(): void
    {
        $service = $this->app->make(StudentDossierPdfService::class);
        $empreinte = $service->empreinte($this->dossier->fresh());

        GenerateStudentDossierPdfJob::dispatchSync(
            $this->dossier->id, $empreinte, $this->admin->id
        );

        $this->assertSame(1, GeneratedDocument::count());
        $this->assertDatabaseHas('historique_academique', ['action' => 'student_dossier.pdf.generated']);
    }
}
