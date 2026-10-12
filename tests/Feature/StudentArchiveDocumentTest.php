<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DocumentAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\StudentArchive\Services\StudentArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Documents projetés (§41) : visibilité, absence, cross-étudiant,
 * download autorisé/refusé via routes GED existantes.
 */
class StudentArchiveDocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.student-archive.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.doc@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->etudiant = $this->creerEtudiant('DOC00001');

        Storage::disk('local')->put('archives/test.pdf', '%PDF-1.4 test');
        DocumentAcademique::create([
            'etudiant_id' => $this->etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'type_document' => 'attestation',
            'nom_original' => 'attestation-scolarite.pdf',
            'chemin' => 'archives/test.pdf',
            'mime_type' => 'application/pdf',
            'taille' => 15,
            'hash_fichier' => hash('sha256', 'test'),
            'uploaded_by' => $this->admin->id,
        ]);
    }

    public function test_document_existant_visible_projete(): void
    {
        $documents = app(StudentArchiveService::class)->documents($this->etudiant, [], $this->admin);

        $this->assertCount(1, $documents);
        $this->assertSame('ged', $documents[0]['source']);
        $this->assertSame('Certificats', $documents[0]['categorie']);
        $this->assertNotNull($documents[0]['telechargement']);
    }

    public function test_document_absent_non_affiche(): void
    {
        $autre = $this->creerEtudiant('DOC00002');

        $this->assertCount(0, app(StudentArchiveService::class)->documents($autre, [], $this->admin));
    }

    public function test_onglet_documents_avec_filtres(): void
    {
        $this->actingAs($this->admin)->get(route('admin.etudiants.archive', [
            $this->etudiant, 'onglet' => 'documents', 'type' => 'attestation',
        ]))->assertOk()->assertSee('attestation-scolarite.pdf');

        $this->actingAs($this->admin)->get(route('admin.etudiants.archive', [
            $this->etudiant, 'onglet' => 'documents', 'type' => 'diplome',
        ]))->assertOk()->assertSee('Aucun document pour ces filtres');
    }

    public function test_download_autorise_via_ged(): void
    {
        $document = DocumentAcademique::where('etudiant_id', $this->etudiant->id)->firstOrFail();

        $this->actingAs($this->admin)->get(route('admin.archives.documents.download', $document))
            ->assertOk();
    }

    public function test_download_refuse_sans_permission(): void
    {
        $document = DocumentAcademique::where('etudiant_id', $this->etudiant->id)->firstOrFail();

        $consultation = User::creerCompteBackOffice([
            'name' => 'Cons', 'email' => 'cons.doc@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');

        // consultation : documents.download OK → autorisé (policies existantes).
        $this->actingAs($consultation)->get(route('admin.archives.documents.download', $document))
            ->assertOk();

        $sansRole = User::query()->create([
            'name' => 'Sans rôle', 'email' => 'sansrole.doc@universite.test', 'password' => 'Motdepasse@123',
        ]);
        $sansRole->forceFill(['role' => 'aucun'])->save();

        $this->actingAs($sansRole)->get(route('admin.archives.documents.download', $document))
            ->assertForbidden();
    }

    public function test_document_autre_etudiant_inaccessible(): void
    {
        $autre = $this->creerEtudiant('DOC00003');

        $this->actingAs($this->admin)->get(route('admin.etudiants.archive', [
            $autre, 'onglet' => 'documents',
        ]))->assertOk()->assertDontSee('attestation-scolarite.pdf');
    }

    private function creerEtudiant(string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin).'@doc.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');
        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);

        return $etudiant;
    }
}
