<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DocumentAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\StudentArchive\Services\StudentArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Diplômes projetés (§24, §40) : décisions admis* + pièces diplome.
 * AUCUNE table `student_diplomas_archive` (lecture seule).
 */
class StudentArchiveDiplomaTest extends TestCase
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
        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.dip@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->etudiant = Etudiant::create([
            'cin' => 'DIP00001', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'dip@dip.test', 'date_naissance' => '2004-01-01', 'sexe' => 'F',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L3', 'annee_academique' => '2026-2027',
            'adresse' => 'X', 'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');
        $inscription = $this->etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L3', 'statut' => 'valide',
        ]);

        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id, 'statut' => 'published',
            'created_by' => $this->admin->id, 'published_at' => now(),
        ]);
        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id, 'etudiant_id' => $this->etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'moyenne' => 13.5, 'credits_obtenus' => 180, 'credits_total' => 180,
            'decision' => 'admis', 'publie' => true,
        ]);

        Storage::disk('local')->put('archives/diplome.pdf', '%PDF-1.4 diplome');
        DocumentAcademique::create([
            'etudiant_id' => $this->etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'type_document' => 'diplome',
            'nom_original' => 'diplome-licence.pdf',
            'chemin' => 'archives/diplome.pdf',
            'mime_type' => 'application/pdf',
            'taille' => 20,
            'hash_fichier' => hash('sha256', 'diplome'),
            'uploaded_by' => $this->admin->id,
        ]);
    }

    public function test_diplome_projete_decision_et_piece(): void
    {
        $diplomes = app(StudentArchiveService::class)->diplomas($this->etudiant);

        $this->assertCount(1, $diplomes['decisions']);
        $this->assertSame('admis', $diplomes['decisions'][0]->decision);
        $this->assertCount(1, $diplomes['documents']);
        $this->assertSame('diplome-licence.pdf', $diplomes['documents'][0]['titre']);
    }

    public function test_ajourne_non_diplome(): void
    {
        DecisionDeliberation::where('etudiant_id', $this->etudiant->id)->update(['decision' => 'ajourne']);

        $diplomes = app(StudentArchiveService::class)->diplomas($this->etudiant);

        $this->assertCount(0, $diplomes['decisions']);
        // La pièce reste visible (source documentaire indépendante).
        $this->assertCount(1, $diplomes['documents']);
    }

    public function test_aucune_table_archive_diplome(): void
    {
        $this->assertFalse(
            Schema::hasTable('student_diplomas_archive')
        );
        $this->assertFalse(
            Schema::hasTable('archive_diplomas')
        );
    }

    public function test_onglet_documents_affiche_diplomes(): void
    {
        $this->actingAs($this->admin)->get(route('admin.etudiants.archive', [
            $this->etudiant, 'onglet' => 'documents',
        ]))->assertOk()
            ->assertSee('diplome-licence.pdf')
            ->assertSee('Admis');
    }
}
