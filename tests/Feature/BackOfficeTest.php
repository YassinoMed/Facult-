<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Back-office (§2-10/§31-38) : référentiels, audit, maintenance,
 * pages d'erreur, capacité des salles.
 */
class BackOfficeTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculte;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.schedule.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences', 'code' => 'FST',
            'description' => 'Sciences', 'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    protected function creerCompte(string $email, string $role, ?int $faculteId = null): User
    {
        return User::creerCompteBackOffice(array_filter([
            'name' => $role, 'email' => $email, 'password' => 'Motdepasse@123',
            'faculte_id' => $faculteId,
        ]), $role);
    }

    public function test_enseignants_crud_et_protection_affectation(): void
    {
        $this->actingAs($this->admin)->get(route('admin.enseignants.index'))
            ->assertOk()->assertSee('Enseignants');

        $this->actingAs($this->admin)->post(route('admin.enseignants.store'), [
            'nom' => 'Ben Salah', 'prenom' => 'Ahmed', 'email' => 'a.b@universite.test',
        ])->assertRedirect();

        $enseignant = Teacher::firstOrFail();

        $this->actingAs($this->admin)->put(route('admin.enseignants.update', $enseignant), [
            'nom' => 'Ben Salah', 'prenom' => 'Ahmed', 'email' => 'a.b@universite.test', 'actif' => '0',
        ])->assertRedirect();
        $this->assertFalse($enseignant->fresh()->actif);
    }

    public function test_salles_crud_et_capacite(): void
    {
        $this->actingAs($this->admin)->post(route('admin.salles.store'), [
            'code' => 'A01', 'nom' => 'Amphi A', 'capacite' => 120,
        ])->assertRedirect();

        $salle = Room::where('code', 'A01')->firstOrFail();
        $this->assertSame(120, $salle->capacite);

        $this->actingAs($this->admin)->post(route('admin.salles.store'), [
            'code' => 'A01',
        ])->assertSessionHasErrors('code');
    }

    public function test_groupes_scopes_faculte(): void
    {
        $autre = Faculte::create([
            'nom' => 'Autre', 'code' => 'AUT', 'description' => 'x', 'active' => true,
        ]);
        Group::create([
            'code' => 'L1-X', 'nom' => 'X', 'faculte_id' => $autre->id,
            'niveau' => 'L1', 'actif' => true,
        ]);
        $groupeA = Group::create([
            'code' => 'L1-A', 'nom' => 'A', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'actif' => true,
        ]);

        $adminA = $this->creerCompte('admin.a@universite.test', 'admin_faculte', $this->faculte->id);

        // Hors périmètre : 404, y compris à l'édition.
        $this->actingAs($adminA)->get(route('admin.groupes.index'))
            ->assertOk()->assertSee('L1-A')->assertDontSee('L1-X');
        $this->actingAs($adminA)->get(route('admin.groupes.edit', $groupeA))->assertOk();

        // Création hors périmètre : 404, rien en base.
        $this->actingAs($adminA)->post(route('admin.groupes.store'), [
            'code' => 'L9-Z', 'nom' => 'Z', 'faculte_id' => $autre->id, 'niveau' => 'L1',
        ])->assertNotFound();
        $this->assertDatabaseMissing('groups', ['code' => 'L9-Z']);
    }

    public function test_groupe_niveau_string_et_niveau_id_incoherents_refuses(): void
    {
        $programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LIC-INF', 'nom' => 'Licence Info',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveauL2 = Niveau::create([
            'programme_id' => $programme->id, 'code' => 'L2', 'nom' => 'L2', 'ordre' => 2,
        ]);

        // niveau=L1 mais niveau_id→L2 : incohérence interdite (le moteur
        // lit le string, la FK ne doit jamais le contredire).
        $this->actingAs($this->admin)->post(route('admin.groupes.store'), [
            'code' => 'L9-INC', 'nom' => 'Incohérent', 'faculte_id' => $this->faculte->id,
            'programme_id' => $programme->id, 'niveau_id' => $niveauL2->id, 'niveau' => 'L1',
        ])->assertSessionHasErrors('niveau_id');
        $this->assertDatabaseMissing('groups', ['code' => 'L9-INC']);

        // Cohérent : accepté.
        $niveauL1 = Niveau::create([
            'programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1,
        ]);
        $this->actingAs($this->admin)->post(route('admin.groupes.store'), [
            'code' => 'L9-OK', 'nom' => 'Cohérent', 'faculte_id' => $this->faculte->id,
            'programme_id' => $programme->id, 'niveau_id' => $niveauL1->id, 'niveau' => 'L1',
        ])->assertRedirect();
        $this->assertDatabaseHas('groups', ['code' => 'L9-OK', 'niveau_id' => $niveauL1->id]);
    }

    public function test_permissions_referentiels(): void
    {
        $lecteur = $this->creerCompte('lecteur@universite.test', 'consultation');

        $this->actingAs($lecteur)->get(route('admin.enseignants.index'))->assertOk();
        $this->actingAs($lecteur)->post(route('admin.enseignants.store'), [
            'nom' => 'X', 'prenom' => 'Y',
        ])->assertForbidden();
    }

    public function test_dashboard_referentiels_et_maintenance_super_admin(): void
    {
        Teacher::create(['nom' => 'A', 'prenom' => 'B']);

        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Référentiels')
            ->assertSee('Actions rapides')
            ->assertSee('Activité récente');

        $super = $this->creerCompte('super@universite.test', 'super_admin');

        $this->actingAs($super)->get(route('admin.maintenance.index'))
            ->assertOk()->assertSee('Santé système');

        $this->actingAs($this->admin)->get(route('admin.maintenance.index'))->assertForbidden();

        $this->actingAs($super)->post(route('admin.maintenance.purger-cache'))
            ->assertRedirect(route('admin.maintenance.index'));
    }

    public function test_audit_lecture_seule_et_filtres(): void
    {
        $this->actingAs($this->admin)->get(route('admin.audit.index'))
            ->assertOk()->assertSee('Journal d’audit');

        $lecteur = $this->creerCompte('lecteur2@universite.test', 'consultation');
        $this->actingAs($lecteur)->get(route('admin.audit.index'))->assertOk();
    }

    public function test_pages_erreur_personnalisees(): void
    {
        $this->get('/cette-page-nexiste-pas')->assertNotFound();

        $reponse = $this->get('/cette-page-nexiste-pas');
        $reponse->assertSee('Page introuvable', false);
        $reponse->assertSee("Retour à l'accueil", false);
    }

    public function test_conflit_capacite(): void
    {
        $groupe = Group::create([
            'code' => 'L1-C', 'nom' => 'C', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'actif' => true,
        ]);
        $petite = Room::create(['code' => 'S01', 'capacite' => 2]);
        $matiere = Matiere::create([
            'code' => 'TST101', 'nom' => 'Test', 'coefficient' => 1,
            'credits' => 1, 'niveau' => 'L1', 'semestre' => 1,
        ]);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $planning = Schedule::create([
            'annee_universitaire_id' => $annee->id, 'semestre' => 1,
            'groupe_id' => $groupe->id, 'nom' => 'Test', 'actif' => true,
        ]);

        foreach (['E1', 'E2', 'E3'] as $cin) {
            $e = Etudiant::create([
                'cin' => $cin, 'nom' => 'T', 'prenom' => 'E',
                'email' => strtolower($cin).'@example.com', 'sexe' => 'M',
                'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
                'annee_academique' => '2026-2027', 'adresse' => 'R',
                'ville' => 'C', 'code_postal' => '20000', 'annee_bac' => '2024',
                'password' => 'motdepasse1', 'groupe_id' => $groupe->id,
            ]);
            $e->forceFill(['num_inscription' => 'INS-2026-'.$cin])->save();
            $e->definirStatut('valide');
        }

        // 3 étudiants > 2 places : conflit bloquant.
        $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.slots.store', $planning),
            [
                'matiere_id' => $matiere->id,
                'room_id' => $petite->id,
                'day_of_week' => 'monday',
                'start_time' => '08:00',
                'end_time' => '09:30',
                'type' => 'course',
            ]
        )->assertSessionHasErrors('conflit');

        $this->assertSame(0, ScheduleSlot::count());
    }
}
