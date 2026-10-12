<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Services\StudentDossierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Isolation BOLA/IDOR (§8/§14) : étudiant A ≠ étudiant B,
 * faculté A ≠ faculté B, super admin global, lecture seule ciblée.
 */
class StudentDocumentIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculteA;

    protected Faculte $faculteB;

    protected Etudiant $etudiantA;

    protected Etudiant $etudiantB;

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

        $this->etudiantA = $this->creerEtudiant($this->faculteA, 'BK970001');
        $this->etudiantB = $this->creerEtudiant($this->faculteB, 'BK970002');
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

    protected function fauxPdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('doc.pdf', '%PDF-1.4 test');
    }

    /**
     * @return array<string, mixed>
     */
    protected function donnees(): array
    {
        return [
            'document' => $this->fauxPdf(),
            'category' => 'identity',
            'document_type' => 'cin',
            'title' => 'CIN recto',
        ];
    }

    public function test_etudiant_a_ne_voit_pas_le_dossier_de_b(): void
    {
        $service = $this->app->make(StudentDossierService::class);
        $service->obtenirOuCreer($this->etudiantA);
        $dossierB = $service->obtenirOuCreer($this->etudiantB);

        $documentB = $dossierB->documents()->create([
            'category' => 'identity',
            'document_type' => 'cin',
            'title' => 'CIN de B',
            'original_filename' => 'cin.pdf',
            'storage_path' => 'student-dossiers/b.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 10,
            'sha256' => hash('sha256', 'b'),
            'status' => 'pending',
        ]);

        session(['etudiant_auth_id' => $this->etudiantA->id]);

        // Son espace : OK, sans trace de B.
        $this->get(route('etudiant.dossier.show'))
            ->assertOk()->assertDontSee('CIN de B');

        // Accès direct au document de B : 404 (jamais 403 bavard).
        $this->get(route('etudiant.dossier.documents.download', $documentB))->assertNotFound();
    }

    public function test_admin_faculte_a_bloque_hors_perimetre(): void
    {
        $adminA = $this->creerCompte('admin.a@universite.test', 'admin_faculte', $this->faculteA->id);

        $service = $this->app->make(StudentDossierService::class);
        $dossierA = $service->obtenirOuCreer($this->etudiantA);
        $dossierB = $service->obtenirOuCreer($this->etudiantB);

        // Lecture B : 404.
        $this->actingAs($adminA)->get(route('admin.student-dossiers.show', $dossierB))->assertNotFound();

        // Écriture B : 404, rien en base.
        $this->actingAs($adminA)->post(
            route('admin.student-dossiers.documents.store', $dossierB),
            $this->donnees()
        )->assertNotFound();
        $this->assertSame(0, $dossierB->documents()->count());

        // Registre : seul A visible.
        $this->actingAs($adminA)->get(route('admin.student-dossiers.index'))
            ->assertOk()
            ->assertSee($dossierA->reference)
            ->assertDontSee($dossierB->reference);
    }

    public function test_super_admin_acces_global(): void
    {
        $super = $this->creerCompte('super@universite.test', 'super_admin');

        $service = $this->app->make(StudentDossierService::class);
        $dossierB = $service->obtenirOuCreer($this->etudiantB);

        $this->actingAs($super)->get(route('admin.student-dossiers.show', $dossierB))->assertOk();

        $this->actingAs($super)->post(
            route('admin.student-dossiers.documents.store', $dossierB),
            $this->donnees()
        )->assertRedirect();

        $this->assertSame(1, $dossierB->documents()->count());
    }

    public function test_consultation_lecture_seule(): void
    {
        $lecteur = $this->creerCompte('lecteur@universite.test', 'consultation');

        $service = $this->app->make(StudentDossierService::class);
        $dossierA = $service->obtenirOuCreer($this->etudiantA);

        $this->actingAs($lecteur)->get(route('admin.student-dossiers.index'))->assertOk();
        $this->actingAs($lecteur)->get(route('admin.student-dossiers.show', $dossierA))->assertOk();

        $this->actingAs($lecteur)->post(
            route('admin.student-dossiers.documents.store', $dossierA),
            $this->donnees()
        )->assertForbidden();

        // La section d'audit reste visible (permission audit accordée).
        $this->actingAs($lecteur)->get(route('admin.student-dossiers.show', $dossierA))
            ->assertSee('Audit');
    }

    public function test_agent_ne_valide_pas(): void
    {
        $agent = $this->creerCompte('agent@universite.test', 'agent_scolarite');

        $service = $this->app->make(StudentDossierService::class);
        $dossierA = $service->obtenirOuCreer($this->etudiantA);

        $super = $this->creerCompte('super2@universite.test', 'super_admin');
        $this->actingAs($super)->post(
            route('admin.student-dossiers.documents.store', $dossierA),
            $this->donnees()
        );
        $document = StudentDocument::firstOrFail();

        // Upload OK pour l'agent, validation interdite.
        $this->actingAs($agent)->post(
            route('admin.student-dossiers.documents.store', $dossierA),
            $this->donnees()
        )->assertRedirect();

        $this->actingAs($agent)
            ->patch(route('admin.student-dossiers.documents.validate', $document))
            ->assertForbidden();
        $this->assertSame('pending', $document->fresh()->status);
    }
}
