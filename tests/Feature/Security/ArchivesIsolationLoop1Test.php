<?php

namespace Tests\Feature\Security;

use App\Models\AnneeUniversitaire;
use App\Models\DocumentAcademique;
use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Semestre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * LOOP 1 — Isolation facultaire du module archives (25 routes).
 * Complète IdorSecurityTest : update/export dossier, inscriptions,
 * notes (store/destroy/verrou), documents (store/destroy),
 * configuration, création cross-faculté, 404 sans divulgation.
 */
class ArchivesIsolationLoop1Test extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Matiere $matiere;

    private int $auteurId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create(['nom' => 'FA', 'code' => 'FA', 'description' => 'T', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FB', 'code' => 'FB', 'description' => 'T', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30', 'active' => true,
        ]);
        $this->matiere = Matiere::create([
            'code' => 'WEB101', 'nom' => 'Web', 'coefficient' => 2,
            'credits' => 6, 'niveau' => 'L1', 'semestre' => 1,
        ]);
        $this->auteurId = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => uniqid('sc.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite')->id;
    }

    private function adminFaculteA(): void
    {
        $user = User::creerCompteBackOffice([
            'name' => 'Admin A', 'email' => uniqid('aa.', true).'@scolarite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');
        $this->actingAs($user);
    }

    private function etudiantDe(Faculte $faculte): Etudiant
    {
        return Etudiant::create([
            'cin' => 'BK'.random_int(100000, 999999),
            'nom' => 'Loop1', 'prenom' => 'Test',
            'email' => uniqid('l1.', true).'@example.com',
            'sexe' => 'M', 'faculte_id' => $faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026',
            'adresse' => '1 rue', 'ville' => 'Rabat',
            'code_postal' => '10000', 'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ]);
    }

    private function dossierDe(Etudiant $etudiant): DossierAcademique
    {
        return DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-'.strtoupper(substr(md5(uniqid('', true)), 0, 8)),
            'date_archivage' => now()->toDateString(),
        ]);
    }

    private function inscriptionDe(Etudiant $etudiant): InscriptionAcademique
    {
        return InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id, 'niveau' => 'L1', 'filiere' => 'Info',
            'annee_universitaire_id' => $this->annee->id, 'statut' => 'en_cours',
        ]);
    }

    public function test_update_dossier_autre_faculte_refuse(): void
    {
        $dossierB = $this->dossierDe($this->etudiantDe($this->faculteB));
        $this->adminFaculteA();

        $this->patch(route('admin.archives.update', $dossierB), ['observation' => 'pirate'])
            ->assertForbidden();
        $this->assertSame(null, $dossierB->fresh()->observation);
    }

    public function test_export_dossier_autre_faculte_refuse(): void
    {
        $dossierB = $this->dossierDe($this->etudiantDe($this->faculteB));
        $this->adminFaculteA();

        $this->get(route('admin.archives.export', $dossierB))->assertForbidden();
    }

    public function test_inscription_store_sur_dossier_autre_faculte_refuse(): void
    {
        $dossierB = $this->dossierDe($this->etudiantDe($this->faculteB));
        $this->adminFaculteA();

        $this->post(route('admin.archives.inscriptions.store', $dossierB), [
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1',
        ])->assertForbidden();
        $this->assertSame(0, InscriptionAcademique::where('etudiant_id', $dossierB->etudiant_id)->count());
    }

    public function test_inscription_update_destroy_autre_faculte_refuses(): void
    {
        $inscriptionB = $this->inscriptionDe($this->etudiantDe($this->faculteB));
        $this->adminFaculteA();

        $this->patch(route('admin.archives.inscriptions.update', $inscriptionB), [
            'niveau' => 'L1', 'statut' => 'en_cours', 'filiere' => 'X',
        ])->assertForbidden();
        $this->delete(route('admin.archives.inscriptions.destroy', $inscriptionB))
            ->assertForbidden();
        $this->assertDatabaseHas('inscriptions_academiques', ['id' => $inscriptionB->id]);
    }

    public function test_note_store_destroy_verrou_autre_faculte_refuses(): void
    {
        $etudiantB = $this->etudiantDe($this->faculteB);
        $inscriptionB = $this->inscriptionDe($etudiantB);
        $semestre = Semestre::create(['inscription_academique_id' => $inscriptionB->id, 'numero' => 1]);
        $this->adminFaculteA();

        $this->post(route('admin.archives.notes.store', $inscriptionB), [
            'semestre_numero' => 1, 'matiere_id' => $this->matiere->id, 'note' => 15,
        ])->assertForbidden();

        $note = Note::create([
            'inscription_academique_id' => $inscriptionB->id, 'etudiant_id' => $etudiantB->id,
            'semestre_id' => $semestre->id, 'matiere_id' => $this->matiere->id,
            'note' => 12, 'coefficient' => 2, 'credits' => 6,
        ]);

        $this->patch(route('admin.archives.notes.lock', $note))->assertForbidden();
        $this->delete(route('admin.archives.notes.destroy', $note))->assertForbidden();
        $this->assertDatabaseHas('notes', ['id' => $note->id, 'note' => 12]);
    }

    public function test_document_store_destroy_autre_faculte_refuses(): void
    {
        Storage::fake('local');
        $dossierB = $this->dossierDe($this->etudiantDe($this->faculteB));
        $this->adminFaculteA();

        $this->post(route('admin.archives.documents.store', $dossierB), [
            'type_document' => 'autre',
            'document' => UploadedFile::fake()->create('secret.pdf', 10, 'application/pdf'),
        ])->assertForbidden();

        $document = DocumentAcademique::create([
            'etudiant_id' => $dossierB->etudiant_id, 'type_document' => 'autre',
            'nom_original' => 'secret.pdf', 'chemin' => 'archives/x.pdf',
            'mime_type' => 'application/pdf', 'taille' => 10,
            'hash_fichier' => 'x', 'uploaded_by' => $this->auteurId,
        ]);

        $this->delete(route('admin.archives.documents.destroy', $document))->assertForbidden();
        $this->assertDatabaseHas('documents_academiques', ['id' => $document->id]);
    }

    public function test_configuration_ecritures_refusees_au_perimetre_restreint(): void
    {
        $this->adminFaculteA();

        // Lecture : référentiel global (années/matières, compteurs seuls,
        // aucune PII) — 200 par design, comme viewAny l'autorise.
        $reponse = $this->get(route('admin.archives.configuration.index'))->assertOk();
        $this->assertStringNotContainsString('BK', $reponse->getContent());

        // Écritures : can:configuration.manage, hors matrice restreinte.
        $this->post(route('admin.archives.annees.store'), ['libelle' => '2030-2031'])
            ->assertForbidden();
        $this->post(route('admin.archives.matieres.store'), ['code' => 'X'])
            ->assertForbidden();
    }

    public function test_creation_dossier_etudiant_autre_faculte_refusee(): void
    {
        $etudiantB = $this->etudiantDe($this->faculteB);
        $this->adminFaculteA();

        $this->post(route('admin.archives.store'), [
            'etudiant_id' => $etudiantB->id, 'matricule' => 'MAT-PIRATE-1',
        ])->assertForbidden();
        $this->assertSame(0, DossierAcademique::where('etudiant_id', $etudiantB->id)->count());
    }

    public function test_ressource_inexistante_404_sans_divulgation(): void
    {
        $this->adminFaculteA();

        $reponse = $this->get('/admin/archives/999999')->assertNotFound();
        $this->assertStringNotContainsString('FB', $reponse->getContent());
    }

    public function test_controle_positif_perimetre_propre_accepte(): void
    {
        $dossierA = $this->dossierDe($this->etudiantDe($this->faculteA));
        $this->adminFaculteA();

        $this->get(route('admin.archives.show', $dossierA))->assertOk();
        $this->get(route('admin.archives.export', $dossierA))->assertOk();
    }
}
