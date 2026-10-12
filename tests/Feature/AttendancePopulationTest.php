<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Attendance\Models\Presence;
use App\Modules\Schedule\Models\Group;
use App\Services\PopulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Présences via population centrale (§5-8, §31) : feuille PDF, filtres
 * relevés, absents/justifiés depuis la source existante.
 */
class AttendancePopulationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Group $groupe;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.attendance.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.att@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->groupe = Group::create([
            'code' => 'AT1', 'nom' => 'AT1', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'capacite' => 30, 'actif' => true,
        ]);
    }

    public function test_feuille_pdf_via_population(): void
    {
        $this->creerEtudiant('ATT00001');

        $this->actingAs($this->admin)->get(route('admin.presences.feuille', [
            'groupe_id' => $this->groupe->id, 'date' => '2026-09-30', 'seance' => '08:00-10:00',
        ]))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->assertDatabaseHas('integration_logs', [
            'integration' => 'export', 'operation' => 'export.presences.feuille', 'status' => 'completed',
        ]);
    }

    public function test_feuille_vide_expliquee(): void
    {
        $this->actingAs($this->admin)->get(route('admin.presences.feuille', [
            'groupe_id' => $this->groupe->id,
        ]))->assertSessionHasErrors('export');
    }

    public function test_releves_filtres_date_statut_groupe(): void
    {
        $etudiant = $this->creerEtudiant('ATT00002');
        Presence::create([
            'etudiant_id' => $etudiant->id, 'date' => '2026-09-30',
            'seance' => '08:00-10:00', 'statut' => 'absent',
        ]);

        $this->actingAs($this->admin)->get(route('admin.presences.index', [
            'date' => '2026-09-30', 'statut' => 'absent', 'groupe_id' => $this->groupe->id,
        ]))->assertOk()
            ->assertSee('Sara Benali')
            ->assertSee('AT***002')
            ->assertDontSee('ATT00002');
    }

    public function test_absents_depuis_source_existante(): void
    {
        $etudiant = $this->creerEtudiant('ATT00003');
        Presence::create([
            'etudiant_id' => $etudiant->id, 'date' => '2026-09-30',
            'seance' => '08:00-10:00', 'statut' => 'absent',
        ]);

        // Absents = relevés statut absent (aucune nouvelle source).
        $absents = Presence::where('statut', 'absent')
            ->whereHas('etudiant', fn ($q) => $q->where('groupe_id', $this->groupe->id))
            ->pluck('etudiant_id')->all();

        $this->assertSame([$etudiant->id], $absents);

        // Population du groupe intacte (présences ≠ filtre population).
        $population = app(PopulationService::class)
            ->requete('attendance_list', ['groupe_id' => $this->groupe->id], $this->admin)
            ->pluck('etudiants.id')->all();

        $this->assertContains($etudiant->id, $population);
    }

    public function test_idor_feuille_autre_faculte(): void
    {
        $this->creerEtudiant('ATT00004');

        $autreFaculte = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.att@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autreFaculte->id,
        ], 'admin_faculte');

        $this->actingAs($adminFaculte)->get(route('admin.presences.feuille', [
            'groupe_id' => $this->groupe->id,
        ]))->assertNotFound();
    }

    private function creerEtudiant(string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin).'@att.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'groupe_id' => $this->groupe->id,
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
