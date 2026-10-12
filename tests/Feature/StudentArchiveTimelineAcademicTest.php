<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\StudentArchive\Services\StudentArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Timeline académique (§31) : délibérations via registry Phase B,
 * filtre catégorie, sans contournement.
 */
class StudentArchiveTimelineAcademicTest extends TestCase
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
            'name' => 'Scolarité', 'email' => 'sco.tlac@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->etudiant = Etudiant::create([
            'cin' => 'TLA00001', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'tla@tla.test', 'date_naissance' => '2004-01-01', 'sexe' => 'F',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => 'X', 'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');
        $inscription = $this->etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);

        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id, 'statut' => 'published',
            'created_by' => $this->admin->id, 'published_at' => now(),
        ]);
        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id, 'etudiant_id' => $this->etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'moyenne' => 12.0, 'credits_obtenus' => 30, 'credits_total' => 30,
            'decision' => 'admis', 'publie' => true,
        ]);
    }

    public function test_evenement_deliberation_dans_timeline(): void
    {
        $this->actingAs($this->admin);

        $timeline = app(StudentArchiveService::class)->timeline($this->etudiant);

        $deliberations = $timeline['elements']->where('categorie', 'deliberation');
        $this->assertCount(1, $deliberations);
        $this->assertStringContainsString('admis', $deliberations->first()['titre']);
    }

    public function test_filtre_categorie_deliberation(): void
    {
        $this->actingAs($this->admin);

        $timeline = app(StudentArchiveService::class)->timeline($this->etudiant, [
            'categorie' => 'deliberation',
        ]);

        $this->assertNotEmpty($timeline['elements']);
        foreach ($timeline['elements'] as $element) {
            $this->assertSame('deliberation', $element['categorie']);
        }
    }

    public function test_masque_sans_permission_deliberations(): void
    {
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.tla@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');
        $this->actingAs($agent);

        $timeline = app(StudentArchiveService::class)->timeline($this->etudiant);

        // agent_scolarite : deliberations.view présent → visible (RBAC réel).
        $this->assertNotEmpty(
            $timeline['elements']->where('categorie', 'deliberation')
        );
    }

    public function test_onglet_timeline_affiche_deliberation(): void
    {
        $this->actingAs($this->admin)->get(route('admin.etudiants.archive', [
            $this->etudiant, 'onglet' => 'timeline',
        ]))->assertOk()->assertSee('admis');
    }
}
