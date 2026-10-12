<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

/**
 * LOOP SCHEDULE-02 — Verrouillage d'accès : invités, permissions,
 * isolation facultaire, portails, API. Chaque test prouve l'absence
 * de fuite (contenu inspecté, pas seulement le code HTTP).
 */
class ScheduleAccesTest extends TestCase
{
    use ActAsApiClient, RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Matiere $matiereA;

    private Matiere $matiereB;

    private Schedule $planningA;

    private Schedule $planningB;

    private ScheduleSlot $slotA;

    private ScheduleSlot $slotB;

    private Teacher $enseignantA;

    private Teacher $enseignantB;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.schedule.active' => true]);

        $this->faculteA = Faculte::create(['nom' => 'FA', 'code' => 'FA', 'description' => 'T', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FB', 'code' => 'FB', 'description' => 'T', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->matiereA = Matiere::create([
            'code' => 'MATA', 'nom' => 'Matiere Alpha', 'niveau' => 'L1', 'semestre' => 1, 'coefficient' => 2, 'credits' => 4,
        ]);
        $this->matiereB = Matiere::create([
            'code' => 'MATB', 'nom' => 'Matiere Beta', 'niveau' => 'L1', 'semestre' => 1, 'coefficient' => 2, 'credits' => 4,
        ]);

        $this->enseignantA = $this->creerEnseignant($this->faculteA);
        $this->enseignantB = $this->creerEnseignant($this->faculteB);

        $this->planningA = $this->creerPlanning($this->faculteA);
        $this->planningB = $this->creerPlanning($this->faculteB);
        $this->slotA = $this->creerCreneau($this->planningA, $this->matiereA, $this->enseignantA);
        $this->slotB = $this->creerCreneau($this->planningB, $this->matiereB, $this->enseignantB);
    }

    private function creerEnseignant(Faculte $faculte): Teacher
    {
        $departement = Departement::create([
            'code' => 'DEP-'.uniqid(), 'nom' => 'Dép.', 'faculte_id' => $faculte->id,
        ]);

        return Teacher::create([
            'nom' => 'Nom', 'prenom' => 'Prenom', 'email' => 'ens.'.uniqid().'@univ.test',
            'matricule' => 'ENS-'.uniqid(), 'departement_id' => $departement->id,
            'actif' => true, 'statut' => 'actif', 'password' => 'Motdepasse@123',
        ]);
    }

    private function creerPlanning(Faculte $faculte): Schedule
    {
        $groupe = Group::create([
            'code' => 'G-'.uniqid(), 'nom' => 'G', 'niveau' => 'L1',
            'faculte_id' => $faculte->id, 'actif' => true,
        ]);

        return Schedule::create([
            'nom' => 'EDT', 'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $faculte->id, 'groupe_id' => $groupe->id,
            'semestre' => 1, 'statut' => 'publie',
        ]);
    }

    private function creerCreneau(Schedule $planning, Matiere $matiere, Teacher $enseignant): ScheduleSlot
    {
        return ScheduleSlot::create([
            'schedule_id' => $planning->id, 'matiere_id' => $matiere->id,
            'teacher_id' => $enseignant->id, 'day_of_week' => 'monday',
            'start_time' => '08:00:00', 'end_time' => '10:00:00',
            'type' => 'course', 'status' => 'scheduled',
        ]);
    }

    private function adminFaculte(Faculte $faculte): User
    {
        return User::creerCompteBackOffice([
            'name' => 'AF', 'email' => 'af.'.uniqid().'@univ.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $faculte->id,
        ], 'admin_faculte');
    }

    public function test_invite_redirige_sans_ecrire(): void
    {
        $this->post(route('admin.emplois-du-temps.slots.store', $this->planningA), [
            'matiere_id' => $this->matiereA->id, 'day_of_week' => 'tuesday',
            'start_time' => '10:00', 'end_time' => '12:00',
        ])->assertRedirect();

        $this->assertSame(1, ScheduleSlot::where('schedule_id', $this->planningA->id)->count());
    }

    public function test_consultation_lecture_ok_ecritures_403(): void
    {
        $lecteur = User::creerCompteBackOffice([
            'name' => 'L', 'email' => 'lect.'.uniqid().'@univ.test', 'password' => 'Motdepasse@123',
        ], 'consultation');

        $this->actingAs($lecteur)->get(route('admin.emplois-du-temps.show', $this->planningA))->assertOk();
        $this->actingAs($lecteur)->patch(route('admin.emplois-du-temps.slots.update', $this->slotA), [
            'start_time' => '09:00', 'end_time' => '11:00',
        ])->assertForbidden();
        $this->actingAs($lecteur)->delete(route('admin.emplois-du-temps.slots.destroy', $this->slotA))
            ->assertForbidden();

        $this->assertSame('08:00:00', $this->slotA->fresh()->start_time);
    }

    public function test_admin_faculte_bloque_hors_perimetre_contenu(): void
    {
        $adminA = $this->adminFaculte($this->faculteA);

        // Convention projet : existence masquée (404 avant Gate), sans fuite.
        $reponse = $this->actingAs($adminA)->get(route('admin.emplois-du-temps.show', $this->planningB));
        $reponse->assertNotFound();
        $reponse->assertDontSee('Matiere Beta', false);

        $this->actingAs($adminA)->patch(route('admin.emplois-du-temps.slots.update', $this->slotB), [
            'start_time' => '09:00', 'end_time' => '11:00',
        ])->assertNotFound();
        $this->assertSame('08:00:00', $this->slotB->fresh()->start_time);
    }

    public function test_enseignant_ne_voit_pas_seances_collegue(): void
    {
        $reponse = $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->get(route('enseignant.emploi-du-temps.semaine'));

        $reponse->assertOk();
        $reponse->assertSee('Matiere Alpha', false);
        $reponse->assertDontSee('Matiere Beta', false);

        $this->withSession(['enseignant_auth_id' => $this->enseignantA->id])
            ->get(route('enseignant.emploi-du-temps.creneau', $this->slotB))
            ->assertNotFound();
    }

    public function test_api_confirmer_sans_scope_403_et_validation_422(): void
    {
        [, $token] = $this->clientApi(['students:read']);

        $this->postApi('/api/v1/schedules/import/confirm', [
            'fichier' => UploadedFile::fake()->createWithContent('p.csv', "a\n", 'text/csv'),
            'mode' => 'creer',
        ], $token)->assertForbidden();

        // Purge des guards : le 1er token (sans scope) ne doit pas fuiter
        // sur la requête suivante (artefact de test, pas applicatif).
        $this->resetAuthGuards();
        [, $bonToken] = $this->clientApi(['schedules:import']);
        $this->postApi('/api/v1/schedules/import/preview', [], $bonToken)
            ->assertStatus(422);
    }

    public function test_horaires_invalides_rejetes_sans_mutation(): void
    {
        $admin = User::creerCompteBackOffice([
            'name' => 'S', 'email' => 'sco.'.uniqid().'@univ.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->actingAs($admin)->patch(route('admin.emplois-du-temps.slots.update', $this->slotA), [
            'start_time' => '12:00', 'end_time' => '10:00',
        ])->assertSessionHasErrors();

        $this->assertSame('08:00:00', $this->slotA->fresh()->start_time);
        $this->assertSame('10:00:00', $this->slotA->fresh()->end_time);
    }
}
