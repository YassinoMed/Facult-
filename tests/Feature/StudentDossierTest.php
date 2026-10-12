<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Modules\StudentDossiers\Services\StudentDossierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dossiers permanents (§1) : activation, unicité, référence, registre.
 */
class StudentDossierTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculte;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

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
    }

    protected function creerEtudiant(string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => 'Test',
            'prenom' => 'Etudiant',
            'email' => strtolower($cin).'@example.com',
            'telephone' => '+212600000000',
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
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

    protected function activerModule(): void
    {
        config(['modules.student-dossiers.active' => true]);
    }

    public function test_module_desactive_tout_est_404(): void
    {
        config(['modules.student-dossiers.active' => false]);

        $this->actingAs($this->admin)->get('/admin/student-dossiers')->assertNotFound();
        $this->actingAs($this->admin)->post('/admin/student-dossiers', [])->assertNotFound();

        session(['etudiant_auth_id' => $this->creerEtudiant('BK940001')->id]);
        $this->get('/etudiant/dossier')->assertNotFound();
    }

    public function test_module_desactive_sans_navigation_ni_widgets(): void
    {
        config(['modules.student-dossiers.active' => false, 'modules.attendance.active' => false]);

        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertOk()->assertDontSee('Dossiers étudiants');

        session(['etudiant_auth_id' => $this->creerEtudiant('BK940002')->id]);
        $this->get(route('etudiant.dashboard'))
            ->assertOk()
            ->assertDontSee('Mes absences')
            ->assertDontSee('Dossier administratif');
    }

    public function test_creation_dossier_reference_unique(): void
    {
        $this->activerModule();
        $etudiant = $this->creerEtudiant('BK940003');

        $this->actingAs($this->admin)->post(route('admin.student-dossiers.store'), [
            'etudiant_id' => $etudiant->id,
        ])->assertRedirect();

        $dossier = StudentDossier::where('etudiant_id', $etudiant->id)->firstOrFail();
        $this->assertStringStartsWith('DOS-', $dossier->reference);
        $this->assertSame('open', $dossier->status);
    }

    public function test_dossier_unique_par_etudiant(): void
    {
        $this->activerModule();
        $etudiant = $this->creerEtudiant('BK940004');

        $service = $this->app->make(StudentDossierService::class);
        $premier = $service->obtenirOuCreer($etudiant);
        $second = $service->obtenirOuCreer($etudiant);

        $this->assertSame($premier->id, $second->id);
        $this->assertSame(1, StudentDossier::where('etudiant_id', $etudiant->id)->count());

        // La route refuse le doublon avec un message métier.
        $this->actingAs($this->admin)->post(route('admin.student-dossiers.store'), [
            'etudiant_id' => $etudiant->id,
        ])->assertSessionHasErrors('etudiant_id');
    }

    public function test_registre_recherche_et_pagination(): void
    {
        $this->activerModule();
        $service = $this->app->make(StudentDossierService::class);
        $service->obtenirOuCreer($this->creerEtudiant('BK940005'));

        $this->actingAs($this->admin)->get(route('admin.student-dossiers.index'))
            ->assertOk()->assertSee('Dossiers étudiants');

        $this->actingAs($this->admin)
            ->get(route('admin.student-dossiers.index', ['search' => 'BK940005']))
            ->assertOk()->assertSee('BK940005');

        $this->actingAs($this->admin)
            ->get(route('admin.student-dossiers.index', ['search' => 'INTROUVABLE']))
            ->assertOk()->assertSee('Aucun dossier');
    }

    public function test_fiche_dossier_et_widgets_actifs(): void
    {
        $this->activerModule();
        $etudiant = $this->creerEtudiant('BK940006');
        $dossier = $this->app->make(StudentDossierService::class)->obtenirOuCreer($etudiant);

        $this->actingAs($this->admin)->get(route('admin.student-dossiers.show', $dossier))
            ->assertOk()->assertSee($dossier->reference);

        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertOk()->assertSee('Documents en attente');

        session(['etudiant_auth_id' => $etudiant->id]);
        $this->get(route('etudiant.dossier.show'))
            ->assertOk()->assertSee('Mon dossier')->assertSee($dossier->reference);
    }
}
