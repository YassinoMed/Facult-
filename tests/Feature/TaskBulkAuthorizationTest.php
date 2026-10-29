<?php

namespace Tests\Feature;

use App\Models\Faculte;
use App\Models\User;
use App\Modules\Tasks\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP centre de travail — l'action groupée des tâches ne doit jamais
 * contourner les abilities unitaires (D-06/D-13 / M-04).
 */
class TaskBulkAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private User $scolarite;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.tasks.active' => true]);

        $this->faculteA = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FMT', 'code' => 'FMT', 'active' => true]);

        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.bulk@test.tn', 'password' => 'Secret123!',
        ], 'admin_scolarite');
    }

    private function tache(string $cle, ?int $faculteId = null): Task
    {
        return Task::create([
            'titre' => 'Tâche '.$cle,
            'source' => 'manuelle',
            'cle_unique' => $cle,
            'faculte_id' => $faculteId,
        ]);
    }

    public function test_bulk_assigner_exige_tasks_assign_meme_avec_tasks_update(): void
    {
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.bulk@test.tn', 'password' => 'Secret123!',
        ], 'agent_scolarite');

        $tache = $this->tache('bulk-perm');

        $this->actingAs($agent)->post(route('admin.taches.bulk'), [
            'ids' => [$tache->id],
            'action' => 'assigner',
            'assigne_a' => $this->scolarite->id,
        ])->assertForbidden();

        $this->assertNull($tache->fresh()->assigne_a);
    }

    public function test_bulk_refuse_une_cible_inactive(): void
    {
        $inactif = User::creerCompteBackOffice([
            'name' => 'Inactif', 'email' => 'inactif.bulk@test.tn', 'password' => 'Secret123!',
            'actif' => false,
        ], 'agent_scolarite');

        $tache = $this->tache('bulk-inactif');

        $this->actingAs($this->scolarite)->post(route('admin.taches.bulk'), [
            'ids' => [$tache->id],
            'action' => 'assigner',
            'assigne_a' => $inactif->id,
        ])->assertSessionHasErrors('assigne_a');

        $this->assertNull($tache->fresh()->assigne_a);
    }

    public function test_bulk_refuse_une_cible_hors_faculte_de_la_tache(): void
    {
        $agentB = User::creerCompteBackOffice([
            'name' => 'Agent B', 'email' => 'agent.b.bulk@test.tn', 'password' => 'Secret123!',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');

        $tache = $this->tache('bulk-fac', $this->faculteA->id);

        $this->actingAs($this->scolarite)->post(route('admin.taches.bulk'), [
            'ids' => [$tache->id],
            'action' => 'assigner',
            'assigne_a' => $agentB->id,
        ])->assertSessionHasErrors('assigne_a');

        $this->assertNull($tache->fresh()->assigne_a);
    }

    public function test_bulk_assigner_cible_valide_meme_faculte(): void
    {
        $agentA = User::creerCompteBackOffice([
            'name' => 'Agent A', 'email' => 'agent.a.bulk@test.tn', 'password' => 'Secret123!',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $tache = $this->tache('bulk-ok', $this->faculteA->id);

        $this->actingAs($this->scolarite)->post(route('admin.taches.bulk'), [
            'ids' => [$tache->id],
            'action' => 'assigner',
            'assigne_a' => $agentA->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($agentA->id, $tache->fresh()->assigne_a);
    }

    // ── P1 : transitions et assignation unitaires ──────────────────────

    public function test_transition_annuler_exige_tasks_cancel_meme_avec_tasks_update(): void
    {
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent sans annulation', 'email' => 'agent.cancel@test.tn', 'password' => 'Secret123!',
        ], 'agent_scolarite');

        $tache = $this->tache('unit-cancel-perm');

        $this->actingAs($agent)
            ->post(route('admin.taches.transiter', [$tache, 'cancelled']))
            ->assertForbidden();

        $this->assertSame('pending', $tache->fresh()->statut);
    }

    public function test_transition_annuler_autorisee_avec_tasks_cancel(): void
    {
        $tache = $this->tache('unit-cancel-ok');

        $this->actingAs($this->scolarite)
            ->post(route('admin.taches.transiter', [$tache, 'cancelled']))
            ->assertRedirect();

        $this->assertSame('cancelled', $tache->fresh()->statut);
    }

    public function test_assignation_unitaire_refuse_une_cible_inactive(): void
    {
        $inactif = User::creerCompteBackOffice([
            'name' => 'Inactif unitaire', 'email' => 'inactif.unit@test.tn', 'password' => 'Secret123!',
            'actif' => false,
        ], 'agent_scolarite');

        $tache = $this->tache('unit-assign-inactif');

        $this->actingAs($this->scolarite)->post(route('admin.taches.assigner', $tache), [
            'assigne_a' => $inactif->id,
        ])->assertSessionHasErrors('assigne_a');

        $this->assertNull($tache->fresh()->assigne_a);
    }

    public function test_assignation_unitaire_refuse_une_cible_sans_tasks_view(): void
    {
        // Compte actif avec un rôle hérité/invalide : aucune permission tasks.view.
        $sansPermission = User::create([
            'name' => 'Sans permission', 'email' => 'sans.tasks.view@test.tn', 'password' => 'Secret123!',
        ]);
        $sansPermission->forceFill(['role' => ''])->save();

        $tache = $this->tache('unit-assign-perm');

        $this->actingAs($this->scolarite)->post(route('admin.taches.assigner', $tache), [
            'assigne_a' => $sansPermission->id,
        ])->assertSessionHasErrors('assigne_a');

        $this->assertNull($tache->fresh()->assigne_a);
    }

    public function test_assignation_unitaire_refuse_une_cible_hors_faculte_de_la_tache(): void
    {
        $agentB = User::creerCompteBackOffice([
            'name' => 'Agent B unitaire', 'email' => 'agent.b.unit@test.tn', 'password' => 'Secret123!',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');

        $tache = $this->tache('unit-assign-fac', $this->faculteA->id);

        $this->actingAs($this->scolarite)->post(route('admin.taches.assigner', $tache), [
            'assigne_a' => $agentB->id,
        ])->assertSessionHasErrors('assigne_a');

        $this->assertNull($tache->fresh()->assigne_a);
    }

    public function test_assignation_unitaire_cible_valide_meme_faculte(): void
    {
        $agentA = User::creerCompteBackOffice([
            'name' => 'Agent A unitaire', 'email' => 'agent.a.unit@test.tn', 'password' => 'Secret123!',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $tache = $this->tache('unit-assign-ok', $this->faculteA->id);

        $this->actingAs($this->scolarite)->post(route('admin.taches.assigner', $tache), [
            'assigne_a' => $agentA->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($agentA->id, $tache->fresh()->assigne_a);
    }
}
