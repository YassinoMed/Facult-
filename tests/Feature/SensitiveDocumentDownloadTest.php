<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DocumentAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\StudentArchive\Models\StudentSensitiveRecord;
use App\Modules\StudentArchive\Services\StudentArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Téléchargement sensible (§25) : Policy + Scope + Confidentiality +
 * Audit → Download. Jamais de chemin exposé.
 */
class SensitiveDocumentDownloadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private Etudiant $etudiant;

    private StudentSensitiveRecord $avecPiece;

    private StudentSensitiveRecord $sansPiece;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.student-archive.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.sdl@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->etudiant = Etudiant::create([
            'cin' => 'SDL00001', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'sdl@sdl.test', 'date_naissance' => '2004-01-01', 'sexe' => 'F',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => 'X', 'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');
        $this->etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);

        Storage::disk('local')->put('archives/sen.pdf', '%PDF-1.4 sen');
        $document = DocumentAcademique::create([
            'etudiant_id' => $this->etudiant->id,
            'annee_universitaire_id' => $annee->id,
            'type_document' => 'certificat',
            'nom_original' => 'piece-sensible.pdf',
            'chemin' => 'archives/sen.pdf',
            'mime_type' => 'application/pdf',
            'taille' => 14,
            'hash_fichier' => hash('sha256', 'sen'),
            'uploaded_by' => $this->admin->id,
            'niveau_confidentialite' => 'confidentiel',
        ]);

        $this->avecPiece = StudentSensitiveRecord::create([
            'etudiant_id' => $this->etudiant->id, 'type' => 'medical',
            'titre' => 'Dossier avec pièce', 'statut' => 'ouvert',
            'document_source' => 'ged', 'document_id' => $document->id,
            'created_by' => $this->admin->id,
        ]);
        $this->sansPiece = StudentSensitiveRecord::create([
            'etudiant_id' => $this->etudiant->id, 'type' => 'disciplinaire',
            'titre' => 'Dossier sans pièce', 'statut' => 'ouvert',
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_lien_resolu_uniquement_si_communicable(): void
    {
        $service = app(StudentArchiveService::class);

        $this->assertNotNull($service->lienTelechargementSensible($this->avecPiece, $this->admin));
        $this->assertNull($service->lienTelechargementSensible($this->sansPiece, $this->admin));
    }

    public function test_download_trace_et_fichier(): void
    {
        $this->actingAs($this->admin)->get(route('admin.etudiants.archive.sensible.download', [
            $this->etudiant, $this->avecPiece,
        ]))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $this->etudiant->id, 'action' => 'medical_download',
        ]);
    }

    public function test_download_sans_piece_404(): void
    {
        $this->actingAs($this->admin)->get(route('admin.etudiants.archive.sensible.download', [
            $this->etudiant, $this->sansPiece,
        ]))->assertNotFound();
    }

    public function test_download_sans_permission_403(): void
    {
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.sdl@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $this->actingAs($agent)->get(route('admin.etudiants.archive.sensible.download', [
            $this->etudiant, $this->avecPiece,
        ]))->assertForbidden();
    }

    public function test_aucun_chemin_physique_expose(): void
    {
        $contenu = $this->actingAs($this->admin)->get(route('admin.etudiants.archive', [
            $this->etudiant, 'onglet' => 'confidentiel',
        ]))->assertOk()->getContent();

        $this->assertStringNotContainsString('archives/sen.pdf', $contenu);
        $this->assertStringNotContainsString('storage/', $contenu);
    }
}
