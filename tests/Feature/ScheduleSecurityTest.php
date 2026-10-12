<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sécurité des emplois du temps : isolation groupes/facultés,
 * permissions, invités, module désactivé.
 */
class ScheduleSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculteA;

    protected Faculte $faculteB;

    protected Group $groupeA;

    protected Group $groupeB;

    protected Schedule $planningA;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.schedule.active' => true]);

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences', 'code' => 'FST',
            'description' => 'Sciences', 'active' => true,
        ]);
        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Médecine', 'code' => 'FMP',
            'description' => 'Médecine', 'active' => true,
        ]);

        $this->groupeA = Group::create([
            'code' => 'L1-A', 'nom' => 'L1 A',
            'faculte_id' => $this->faculteA->id, 'niveau' => 'L1', 'actif' => true,
        ]);
        $this->groupeB = Group::create([
            'code' => 'L1-B', 'nom' => 'L1 B',
            'faculte_id' => $this->faculteB->id, 'niveau' => 'L1', 'actif' => true,
        ]);

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->planningA = Schedule::create([
            'annee_universitaire_id' => $annee->id, 'semestre' => 1,
            'groupe_id' => $this->groupeA->id, 'nom' => 'A', 'actif' => true,
        ]);
    }

    protected function creerEtudiant(string $cin, Group $groupe): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => 'Test',
            'prenom' => 'Etudiant',
            'email' => strtolower($cin).'@example.com',
            'sexe' => 'M',
            'faculte_id' => $groupe->faculte_id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rue',
            'ville' => 'Casa',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'password' => 'motdepasse1',
            'groupe_id' => $groupe->id,
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-'.$cin])->save();
        $etudiant->definirStatut('valide');

        return $etudiant->fresh();
    }

    protected function creerCompte(string $email, string $role, ?int $faculteId = null): User
    {
        return User::creerCompteBackOffice(array_filter([
            'name' => $role,
            'email' => $email,
            'password' => 'Motdepasse@123',
            'faculte_id' => $faculteId,
        ]), $role);
    }

    public function test_module_desactive_404(): void
    {
        config(['modules.schedule.active' => false]);

        $admin = $this->creerCompte('sco@universite.test', 'admin_scolarite');
        $this->actingAs($admin)->get('/admin/emplois-du-temps')->assertNotFound();

        session(['etudiant_auth_id' => $this->creerEtudiant('BK940001', $this->groupeA)->id]);
        $this->get('/etudiant/emploi-du-temps')->assertNotFound();
    }

    public function test_etudiant_autre_groupe_bloque(): void
    {
        $etudiantB = $this->creerEtudiant('BK940002', $this->groupeB);

        session(['etudiant_auth_id' => $etudiantB->id]);

        // Son groupe n'a aucun planning → 404, jamais celui de A.
        $this->get(route('etudiant.emploi-du-temps.semaine'))->assertNotFound();
        $this->getJson(route('etudiant.emploi-du-temps.actualisations'))->assertNotFound();
    }

    public function test_etudiant_ne_peut_pas_ecrire(): void
    {
        $etudiantA = $this->creerEtudiant('BK940003', $this->groupeA);

        session(['etudiant_auth_id' => $etudiantA->id]);

        // Routes back-office hors de portée : redirection connexion scolarité.
        $this->post(route('admin.emplois-du-temps.store'), [])->assertRedirect(route('admin.connexion.show'));
        $this->get(route('admin.emplois-du-temps.index'))->assertRedirect(route('admin.connexion.show'));
    }

    public function test_admin_faculte_bloque_hors_perimetre(): void
    {
        $adminA = $this->creerCompte('admin.a@universite.test', 'admin_faculte', $this->faculteA->id);

        $planningB = Schedule::create([
            'annee_universitaire_id' => $this->planningA->annee_universitaire_id,
            'semestre' => 1, 'groupe_id' => $this->groupeB->id, 'nom' => 'B', 'actif' => true,
        ]);

        $this->actingAs($adminA)->get(route('admin.emplois-du-temps.show', $planningB))->assertNotFound();
        $this->actingAs($adminA)->get(route('admin.emplois-du-temps.index'))
            ->assertOk()->assertDontSee('>B<');
    }

    public function test_consultation_lecture_seule_et_super_admin(): void
    {
        $lecteur = $this->creerCompte('lecteur@universite.test', 'consultation');
        $super = $this->creerCompte('super@universite.test', 'super_admin');

        $this->actingAs($lecteur)->get(route('admin.emplois-du-temps.index'))->assertOk();
        $this->actingAs($lecteur)->post(route('admin.emplois-du-temps.store'), [])->assertForbidden();

        $this->actingAs($super)->get(route('admin.emplois-du-temps.show', $this->planningA))->assertOk();
    }

    public function test_invites_rediriges(): void
    {
        $this->get('/admin/emplois-du-temps')->assertRedirect(route('admin.connexion.show'));
        $this->get('/etudiant/emploi-du-temps')->assertRedirect(route('etudiant.connexion.show'));
    }

    /**
     * SCHEDULE-02 / S02-4 — IDOR au niveau créneau : un admin_faculte ne
     * peut cibler par identifiant aucun créneau d'une autre faculté
     * (update, déplacement, annulation, restauration, suppression,
     * exception) — 404 avant toute mutation, rien en base.
     */
    public function test_admin_faculte_idor_slot_hors_perimetre_404(): void
    {
        $adminA = $this->creerCompte('admin.idor@universite.test', 'admin_faculte', $this->faculteA->id);

        $planningB = Schedule::create([
            'annee_universitaire_id' => $this->planningA->annee_universitaire_id,
            'semestre' => 1, 'groupe_id' => $this->groupeB->id, 'nom' => 'B-IDOR', 'actif' => true,
        ]);
        $matiere = Matiere::create(['code' => 'SEC-IDOR', 'nom' => 'Sécurité', 'niveau' => 'L1', 'semestre' => 1]);
        $slotB = ScheduleSlot::create([
            'schedule_id' => $planningB->id, 'matiere_id' => $matiere->id,
            'day_of_week' => 'monday', 'start_time' => '08:00:00', 'end_time' => '10:00:00',
            'type' => 'course', 'status' => 'scheduled',
        ]);

        // Update partiel valide (notes) : le 404 de périmètre précède toute écriture.
        $this->actingAs($adminA)->patch(route('admin.emplois-du-temps.slots.update', $slotB), [
            'notes' => 'Tentative IDOR',
        ])->assertNotFound();

        // Déplacement avec payload valide.
        $this->actingAs($adminA)->patch(route('admin.emplois-du-temps.slots.deplacer', $slotB), [
            'day_of_week' => 'tuesday', 'start_time' => '14:00', 'end_time' => '16:00',
        ])->assertNotFound();

        $this->actingAs($adminA)->post(route('admin.emplois-du-temps.slots.cancel', $slotB), [
            'motif' => 'Tentative IDOR',
        ])->assertNotFound();

        $this->actingAs($adminA)->post(route('admin.emplois-du-temps.slots.restore', $slotB))
            ->assertNotFound();

        $this->actingAs($adminA)->delete(route('admin.emplois-du-temps.slots.destroy', $slotB))
            ->assertNotFound();

        $this->actingAs($adminA)->post(route('admin.emplois-du-temps.slots.exceptions.store', $slotB), [
            'exception_date' => now()->addDays(7)->toDateString(),
            'status' => 'cancelled', 'reason' => 'Tentative IDOR',
        ])->assertNotFound();

        // Rien n'a bougé : créneau intact, aucune exception créée.
        $this->assertDatabaseHas('schedule_slots', [
            'id' => $slotB->id, 'schedule_id' => $planningB->id,
            'day_of_week' => 'monday', 'status' => 'scheduled',
        ]);
        $this->assertSame('course', $slotB->fresh()->type);
        $this->assertDatabaseMissing('schedule_exceptions', ['schedule_slot_id' => $slotB->id]);
        $this->assertDatabaseMissing('schedule_changes', ['schedule_slot_id' => $slotB->id]);
    }

    /**
     * Contrôle positif S02-4 : le même admin sur un créneau de SA faculté
     * n'est pas bloqué (parcours légitime préservé).
     */
    public function test_admin_faculte_slot_dans_perimetre_autorise(): void
    {
        $adminA = $this->creerCompte('admin.legit@universite.test', 'admin_faculte', $this->faculteA->id);

        $matiere = Matiere::create(['code' => 'SEC-OK', 'nom' => 'Légitime', 'niveau' => 'L1', 'semestre' => 1]);
        $slotA = ScheduleSlot::create([
            'schedule_id' => $this->planningA->id, 'matiere_id' => $matiere->id,
            'day_of_week' => 'wednesday', 'start_time' => '08:00:00', 'end_time' => '10:00:00',
            'type' => 'course', 'status' => 'scheduled',
        ]);

        $this->actingAs($adminA)->patch(route('admin.emplois-du-temps.slots.update', $slotA), [
            'notes' => 'Note légitime',
        ])->assertRedirect();

        $this->assertSame('Note légitime', $slotA->fresh()->notes);
    }
}
