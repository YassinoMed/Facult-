<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\SituationEtudiant;
use App\Models\User;
use App\Modules\StudentArchive\Policies\StudentArchivePolicy;
use App\Modules\StudentArchive\Services\ArchiveTimelineRegistry;
use App\Modules\StudentArchive\Services\StudentArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Archive étudiant Phase B (§49) : module, timeline, policies, audit.
 */
class StudentArchiveTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.arch@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->etudiant = $this->creerEtudiant('ARC00001');
    }

    public function test_module_desactive_repond_404(): void
    {
        config(['modules.student-archive.active' => false]);

        $this->actingAs($this->admin)->get(route('admin.etudiants.archive', $this->etudiant))
            ->assertNotFound();
    }

    public function test_vue_archive_consolidee_et_auditee(): void
    {
        config(['modules.student-archive.active' => true]);

        $reponse = $this->actingAs($this->admin)->get(route('admin.etudiants.archive', $this->etudiant));

        $reponse->assertOk();
        $reponse->assertSee($this->etudiant->nom_complet);
        $reponse->assertSee('Parcours longitudinal');
        $reponse->assertSee('Inscription 2026-2027', false);

        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $this->etudiant->id,
            'action' => HistoriqueAcademique::ACTION_ARCHIVE_VIEW,
            'entite' => 'archive',
        ]);
    }

    public function test_idor_autre_faculte_404(): void
    {
        config(['modules.student-archive.active' => true]);

        $autreFaculte = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.arch@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autreFaculte->id,
        ], 'admin_faculte');

        $this->actingAs($adminFaculte)->get(route('admin.etudiants.archive', $this->etudiant))
            ->assertNotFound();
        $this->assertDatabaseMissing('historique_academique', [
            'etudiant_id' => $this->etudiant->id,
            'action' => HistoriqueAcademique::ACTION_ARCHIVE_VIEW,
        ]);
    }

    public function test_sans_permission_403(): void
    {
        config(['modules.student-archive.active' => true]);

        $sansRole = User::query()->create([
            'name' => 'Sans rôle', 'email' => 'sansrole.arch@universite.test', 'password' => 'Motdepasse@123',
        ]);
        $sansRole->forceFill(['role' => 'aucun'])->save();

        $this->actingAs($sansRole)->get(route('admin.etudiants.archive', $this->etudiant))
            ->assertForbidden();
    }

    public function test_timeline_fusionnee_triee_paginee(): void
    {
        SituationEtudiant::create([
            'etudiant_id' => $this->etudiant->id, 'type' => 'statut',
            'ancienne_valeur' => 'en_attente', 'nouvelle_valeur' => 'valide',
            'motif' => 'Validation', 'auteur_id' => $this->admin->id,
            'date_effet' => '2026-09-02',
        ]);

        $service = app(StudentArchiveService::class);

        $page1 = $service->timeline($this->etudiant, ['limite' => 1, 'offset' => 0]);
        $page2 = $service->timeline($this->etudiant, ['limite' => 1, 'offset' => 1]);

        $this->assertGreaterThanOrEqual(2, $page1['total']);
        $this->assertCount(1, $page1['elements']);
        $this->assertNotSame(
            $page1['elements'][0]['titre'].$page1['elements'][0]['date'],
            $page2['elements'][0]['titre'].$page2['elements'][0]['date']
        );

        $filtres = $service->timeline($this->etudiant, ['categorie' => 'inscription']);
        $this->assertNotEmpty($filtres['elements']);
        foreach ($filtres['elements'] as $element) {
            $this->assertSame('inscription', $element['categorie']);
        }

        $categories = app(ArchiveTimelineRegistry::class)->categories();

        foreach (['inscription', 'groupe', 'situation', 'document', 'deliberation', 'demande', 'absence'] as $attendues) {
            $this->assertContains($attendues, $categories);
        }
    }

    public function test_statistiques_sources_existantes(): void
    {
        $stats = app(StudentArchiveService::class)->statistics($this->etudiant);

        $this->assertSame(1, $stats['annees']);
        $this->assertSame(1, $stats['inscriptions']);
    }

    public function test_policy_faculte_et_audit(): void
    {
        $policy = app(StudentArchivePolicy::class);

        $this->assertTrue($policy->voir($this->admin, $this->etudiant));
        $this->assertTrue($policy->voirAudit($this->admin, $this->etudiant));

        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.arch@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        // agent_scolarite : pas de student_archive.audit.view (gradient RBAC).
        $this->assertTrue($policy->voir($agent, $this->etudiant));
        $this->assertFalse($policy->voirAudit($agent, $this->etudiant));

        $autreFaculte = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac2', 'email' => 'fac2.arch@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autreFaculte->id,
        ], 'admin_faculte');

        $this->assertFalse($policy->voir($adminFaculte, $this->etudiant));
    }

    private function creerEtudiant(string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin).'@arch.test',
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
