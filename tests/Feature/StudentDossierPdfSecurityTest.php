<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\StudentDossiers\Models\GeneratedDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Modules\StudentDossiers\Services\Pdf\StudentDossierPdfService;
use App\Modules\StudentDossiers\Services\StudentDossierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sécurité PDF (§16) : isolation étudiant/faculté, permissions,
 * invités, secrets absents du PDF servi.
 */
class StudentDossierPdfSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculteA;

    protected Faculte $faculteB;

    protected Etudiant $etudiantA;

    protected Etudiant $etudiantB;

    protected StudentDossier $dossierA;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.student-dossiers.active' => true]);
        Storage::fake('private');

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences', 'code' => 'FST',
            'description' => 'Sciences', 'active' => true,
        ]);
        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Médecine', 'code' => 'FMP',
            'description' => 'Médecine', 'active' => true,
        ]);

        $this->etudiantA = $this->creerEtudiant($this->faculteA, 'BK990001');
        $this->etudiantB = $this->creerEtudiant($this->faculteB, 'BK990002');

        $service = $this->app->make(StudentDossierService::class);
        $this->dossierA = $service->obtenirOuCreer($this->etudiantA);
        $service->obtenirOuCreer($this->etudiantB);
    }

    protected function creerEtudiant(Faculte $faculte, string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => 'Test',
            'prenom' => 'Etudiant',
            'email' => strtolower($cin).'@example.com',
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
        $etudiant->forceFill(['num_inscription' => 'INS-2026-'.$cin])->save();
        $etudiant->definirStatut('valide');

        return $etudiant->fresh();
    }

    protected function creerCompte(string $email, string $role, ?int $faculteId = null): User
    {
        return User::creerCompteBackOffice(array_filter([
            'name' => $role,
            'email' => $email,
            'password' => 'Motdepasse@123',
            'faculte_id' => $faculteId,
        ]), $role);
    }

    public function test_etudiant_a_sans_acces_donnees_b(): void
    {
        session(['etudiant_auth_id' => $this->etudiantA->id]);

        $this->get(route('etudiant.dossier.pdf.download'))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // Le binaire PDF encode le texte en glyphes (police sous-ensemblées) :
        // l'isolation des données se vérifie sur la source HTML déterministe.
        $service = $this->app->make(StudentDossierPdfService::class);
        $dossierA = StudentDossier::where('etudiant_id', $this->etudiantA->id)->firstOrFail();
        $html = view('modules.student-dossiers.pdf.dossier', $service->donnees($dossierA))->render();

        $this->assertStringContainsString('BK990001', $html);
        $this->assertStringNotContainsString('BK990002', $html);
        $this->assertStringNotContainsString('motdepasse1', $html);
    }

    public function test_admin_faculte_bloque_hors_perimetre(): void
    {
        $adminA = $this->creerCompte('admin.a@universite.test', 'admin_faculte', $this->faculteA->id);
        $dossierB = StudentDossier::where('etudiant_id', $this->etudiantB->id)->firstOrFail();

        $this->actingAs($adminA)->get(route('admin.student-dossiers.pdf.preview', $dossierB))->assertNotFound();
        $this->actingAs($adminA)->get(route('admin.student-dossiers.pdf.download', $dossierB))->assertNotFound();
        $this->actingAs($adminA)->post(route('admin.student-dossiers.pdf.generer', $dossierB))->assertNotFound();
    }

    public function test_super_admin_acces_global(): void
    {
        $super = $this->creerCompte('super@universite.test', 'super_admin');
        $dossierB = StudentDossier::where('etudiant_id', $this->etudiantB->id)->firstOrFail();

        $this->actingAs($super)->post(route('admin.student-dossiers.pdf.generer', $dossierB))
            ->assertRedirect(route('admin.student-dossiers.pdf.preview', $dossierB));
        $this->actingAs($super)->get(route('admin.student-dossiers.pdf.download', $dossierB))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_admin_faculte_pdf_hors_perimetre(): void
    {
        $adminA = $this->creerCompte('admin.a@universite.test', 'admin_faculte', $this->faculteA->id);
        $dossierB = StudentDossier::where('etudiant_id', $this->etudiantB->id)->firstOrFail();

        $this->actingAs($adminA)->get(route('admin.student-dossiers.pdf.preview', $dossierB))->assertNotFound();
        $this->actingAs($adminA)->get(route('admin.student-dossiers.pdf.download', $dossierB))->assertNotFound();
        $this->actingAs($adminA)->post(route('admin.student-dossiers.pdf.generer', $dossierB))->assertNotFound();

        $this->assertSame(0, GeneratedDocument::count());
    }

    public function test_invites_rediriges(): void
    {
        $this->get(route('admin.student-dossiers.pdf.preview', $this->dossierA))
            ->assertRedirect(route('admin.connexion.show'));
        $this->get(route('etudiant.dossier.pdf.preview'))
            ->assertRedirect(route('etudiant.connexion.show'));
    }

    public function test_pdf_servi_sans_secrets_ni_chemins(): void
    {
        $super = $this->creerCompte('super2@universite.test', 'super_admin');

        $contenu = $this->actingAs($super)->get(
            route('admin.student-dossiers.pdf.download', $this->dossierA)
        )->streamedContent();

        $this->assertTrue(str_starts_with($contenu, '%PDF'));

        // Secrets : vérifiés sur la source HTML (le binaire encode le
        // texte en glyphes — cf. test_contenu_html_a4_footer_sans_secrets).
        $service = $this->app->make(StudentDossierPdfService::class);
        $html = view(
            'modules.student-dossiers.pdf.dossier',
            $service->donnees($this->dossierA->fresh(['etudiant.faculte', 'etudiant.dossierAcademique', 'documents.versions']))
        )->render();

        foreach (['motdepasse1', '$2y$', 'remember_token', 'storage_path', 'storage/app', 'APP_KEY'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $html, "Fuite : {$interdit}.");
        }
    }
}
