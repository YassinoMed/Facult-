<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Calendar\Models\EvenementCalendrier;
use App\Modules\CentreServicesIt\Models\CategorieIt;
use App\Modules\CentreServicesIt\Models\TicketIt;
use App\Modules\Tasks\Models\Task;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LOOP-11 — centre de services IT.
 *
 * Preuves attendues :
 * - module désactivé ⇒ 404 sur toutes ses routes ;
 * - module Tasks désactivé ⇒ aucune création inventée (409 explicite) ;
 * - création idempotente (une clé = un ticket = une tâche) ;
 * - SLA calculé en jours ouvrés (week-end et jours fériés publiés exclus) ;
 * - transitions et affectation déléguées à TaskService (aucune FSM parallèle) ;
 * - escalade idempotente, sans repousser l'échéance ;
 * - isolation facultaire en lecture, en création et sur les statistiques ;
 * - permissions `it.*` explicites (consultation ≠ agent ≠ administrateur).
 */
class CentreServicesItTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private CategorieIt $categorie;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.tasks.active' => true,
            'modules.centre-services-it.active' => true,
            'modules.calendar.active' => false,
        ]);

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences', 'code' => 'FSA',
            'description' => 'Test', 'active' => true,
        ]);
        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Droit', 'code' => 'FDB',
            'description' => 'Test', 'active' => true,
        ]);

        $this->admin = $this->creerUtilisateur('admin_scolarite');
        $this->categorie = CategorieIt::where('code', 'compte')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_module_desactive_repond_404_sur_toutes_ses_routes(): void
    {
        config(['modules.centre-services-it.active' => false]);

        $this->actingAs($this->admin)
            ->getJson(route('admin.centre-services-it.tickets.index'))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->postJson(route('admin.centre-services-it.tickets.store'), $this->chargeTicket())
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->getJson(route('admin.centre-services-it.categories.index'))
            ->assertNotFound();
    }

    public function test_creation_idempotente_cree_un_ticket_et_une_seule_tache(): void
    {
        $cle = (string) Str::uuid();
        $charge = $this->chargeTicket(['cle_idempotence' => $cle]);

        $premiere = $this->actingAs($this->admin)
            ->postJson(route('admin.centre-services-it.tickets.store'), $charge)
            ->assertCreated()
            ->assertJsonPath('statut', 'pending')
            ->assertJsonPath('priorite', 'P2');

        $id = (int) $premiere->json('id');

        $rejeu = $this->actingAs($this->admin)
            ->postJson(route('admin.centre-services-it.tickets.store'), $charge)
            ->assertOk()
            ->assertJsonPath('id', $id);

        $this->assertSame($premiere->json('reference'), $rejeu->json('reference'));
        $this->assertSame(1, TicketIt::count());
        $this->assertSame(1, Task::where('source', 'centre_services_it')->count());

        $ticket = TicketIt::with('tache')->findOrFail($id);

        $this->assertSame(sprintf('IT-%s-%06d', now()->format('Y'), $id), $ticket->reference);
        $this->assertSame('high', $ticket->tache->priorite);
        $this->assertSame($this->faculteA->id, $ticket->faculte_id);
        $this->assertSame($this->faculteA->id, $ticket->tache->faculte_id);
        $this->assertSame($this->admin->id, $ticket->demandeur_id);
    }

    public function test_le_sla_est_calcule_en_jours_ouvres_et_exclut_le_week_end(): void
    {
        // Vendredi 30/10/2026 : un SLA P1 d'un jour ouvre au lundi 02/11.
        Carbon::setTestNow('2026-10-30 10:00:00');

        $reponse = $this->actingAs($this->admin)
            ->postJson(route('admin.centre-services-it.tickets.store'), $this->chargeTicket([
                'priorite' => 'P1',
            ]))
            ->assertCreated();

        $ticket = TicketIt::findOrFail((int) $reponse->json('id'));

        $this->assertSame('2026-11-02', $ticket->echeance_sla->format('Y-m-d'));
    }

    public function test_les_jours_feries_publies_sont_exclus_du_sla(): void
    {
        Carbon::setTestNow('2026-10-30 10:00:00');
        config(['modules.calendar.active' => true]);

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
        ]);

        EvenementCalendrier::create([
            'annee_universitaire_id' => $annee->id,
            'titre' => 'Jour férié de test',
            'type' => 'ferie',
            'date_debut' => '2026-11-02',
            'date_fin' => '2026-11-02',
            'audience' => 'tous',
            'statut' => 'publie',
        ]);

        $reponse = $this->actingAs($this->admin)
            ->postJson(route('admin.centre-services-it.tickets.store'), $this->chargeTicket([
                'priorite' => 'P1',
            ]))
            ->assertCreated();

        $ticket = TicketIt::findOrFail((int) $reponse->json('id'));

        $this->assertSame('2026-11-03', $ticket->echeance_sla->format('Y-m-d'));
    }

    public function test_transition_illegale_refusee_et_transition_valide_journalisee(): void
    {
        $ticket = $this->creerTicket();

        $this->actingAs($this->admin)
            ->postJson(route('admin.centre-services-it.tickets.transition', $ticket), [
                'statut' => 'in_progress',
                'commentaire' => 'Prise en charge',
            ])
            ->assertOk()
            ->assertJsonPath('statut', 'in_progress');

        $this->assertSame('in_progress', $ticket->fresh()->tache->statut);

        $this->actingAs($this->admin)
            ->postJson(route('admin.centre-services-it.tickets.transition', $ticket), [
                'statut' => 'completed',
            ])
            ->assertOk()
            ->assertJsonPath('statut', 'completed');

        // completed est terminal : toute nouvelle transition est refusée.
        $this->actingAs($this->admin)
            ->postJson(route('admin.centre-services-it.tickets.transition', $ticket), [
                'statut' => 'in_progress',
            ])
            ->assertStatus(409);

        $this->assertDatabaseHas('it_evenements', [
            'ticket_id' => $ticket->id,
            'type' => 'transition',
            'vers' => 'completed',
        ]);
        $this->assertDatabaseHas('task_evenements', [
            'task_id' => $ticket->task_id,
            'action' => 'statut:completed',
        ]);
    }

    public function test_escalade_idempotente_et_sans_report_de_l_echeance(): void
    {
        $ticket = $this->creerTicket(['priorite' => 'P3']);
        $echeance = $ticket->echeance_sla->format('Y-m-d H:i:s');
        $cle = (string) Str::uuid();

        $this->actingAs($this->admin)
            ->postJson(route('admin.centre-services-it.tickets.escalader', $ticket), [
                'cle_idempotence' => $cle,
                'motif' => 'Premier niveau',
            ])
            ->assertOk()
            ->assertJsonPath('priorite', 'P2');

        // Rejeu de la même clé : aucun second niveau.
        $this->actingAs($this->admin)
            ->postJson(route('admin.centre-services-it.tickets.escalader', $ticket), [
                'cle_idempotence' => $cle,
                'motif' => 'Premier niveau',
            ])
            ->assertOk()
            ->assertJsonPath('priorite', 'P2');

        $this->assertSame(1, $ticket->fresh()->evenements()->where('type', 'escalade')->count());

        $this->actingAs($this->admin)
            ->postJson(route('admin.centre-services-it.tickets.escalader', $ticket), [
                'cle_idempotence' => (string) Str::uuid(),
                'motif' => 'Deuxième niveau',
            ])
            ->assertOk()
            ->assertJsonPath('priorite', 'P1');

        $frais = $ticket->fresh();

        $this->assertSame('critical', $frais->tache->priorite);
        $this->assertSame($echeance, $frais->echeance_sla->format('Y-m-d H:i:s'));
        $this->assertNotNull($frais->escalade_le);
    }

    public function test_affectation_controlee_par_permission_et_perimetre(): void
    {
        $ticket = $this->creerTicket();
        $agent = $this->creerUtilisateur('agent_scolarite', $this->faculteA);
        $consultation = $this->creerUtilisateur('consultation', $this->faculteA);
        $horsPerimetre = $this->creerUtilisateur('admin_faculte', $this->faculteB);

        $this->actingAs($this->admin)
            ->postJson(route('admin.centre-services-it.tickets.assigner', $ticket), [
                'assigne_a' => $agent->id,
            ])
            ->assertOk();

        $this->assertSame($agent->id, $ticket->fresh()->tache->assigne_a);

        // Consultation : aucune permission it.manage.
        $this->actingAs($consultation)
            ->postJson(route('admin.centre-services-it.tickets.assigner', $ticket), [
                'assigne_a' => $agent->id,
            ])
            ->assertForbidden();

        // Agent compétent mais hors faculté : refus explicite.
        $this->actingAs($this->admin)
            ->postJson(route('admin.centre-services-it.tickets.assigner', $ticket), [
                'assigne_a' => $horsPerimetre->id,
            ])
            ->assertForbidden();
    }

    public function test_isolation_facultaire_en_lecture_en_creation_et_en_statistiques(): void
    {
        $ticketA = $this->creerTicket();

        $ticketB = $this->actingAs($this->admin)
            ->postJson(route('admin.centre-services-it.tickets.store'), $this->chargeTicket([
                'faculte_id' => $this->faculteB->id,
            ]))
            ->assertCreated();

        $adminA = $this->creerUtilisateur('admin_faculte', $this->faculteA);

        // Lecture d'un ticket d'une autre faculté : 404 (pas 403, pas de fuite).
        $this->actingAs($adminA)
            ->getJson(route('admin.centre-services-it.tickets.show', (int) $ticketB->json('id')))
            ->assertNotFound();

        // Création explicite pour une autre faculté : refus.
        $this->actingAs($adminA)
            ->postJson(route('admin.centre-services-it.tickets.store'), $this->chargeTicket([
                'faculte_id' => $this->faculteB->id,
            ]))
            ->assertForbidden();

        // Liste et statistiques bornées à sa faculté.
        $liste = $this->actingAs($adminA)
            ->getJson(route('admin.centre-services-it.tickets.index'))
            ->assertOk();

        $this->assertSame([$ticketA->id], collect($liste->json('data'))->pluck('id')->all());

        $this->actingAs($adminA)
            ->getJson(route('admin.centre-services-it.statistiques'))
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_module_tasks_desactive_refuse_la_creation_sans_inventer_de_ticket(): void
    {
        config(['modules.tasks.active' => false]);

        $reponse = $this->actingAs($this->admin)
            ->postJson(route('admin.centre-services-it.tickets.store'), $this->chargeTicket())
            ->assertStatus(409);

        $this->assertStringContainsString('Tâches', (string) $reponse->json('erreur'));

        $this->assertSame(0, TicketIt::count());
        $this->assertSame(0, Task::where('source', 'centre_services_it')->count());
    }

    public function test_permissions_consultation_lecture_seule_et_agent_creation(): void
    {
        $consultation = $this->creerUtilisateur('consultation', $this->faculteA);
        $agent = $this->creerUtilisateur('agent_scolarite', $this->faculteA);

        $this->actingAs($consultation)
            ->getJson(route('admin.centre-services-it.tickets.index'))
            ->assertOk();

        $this->actingAs($consultation)
            ->postJson(route('admin.centre-services-it.tickets.store'), $this->chargeTicket())
            ->assertForbidden();

        $this->actingAs($agent)
            ->postJson(route('admin.centre-services-it.tickets.store'), $this->chargeTicket())
            ->assertCreated();
    }

    public function test_le_catalogue_est_modifiable_mais_reserve_a_it_categories(): void
    {
        $agent = $this->creerUtilisateur('agent_scolarite', $this->faculteA);

        $charge = [
            'code' => 'impression',
            'libelle' => 'Impression et scanners',
            'sla_jours_p1' => 1,
            'sla_jours_p2' => 2,
            'sla_jours_p3' => 4,
            'priorite_defaut' => 'P3',
        ];

        $this->actingAs($agent)
            ->postJson(route('admin.centre-services-it.categories.store'), $charge)
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->postJson(route('admin.centre-services-it.categories.store'), $charge)
            ->assertCreated()
            ->assertJsonPath('origine', 'saisi');

        $categorie = CategorieIt::where('code', 'impression')->firstOrFail();

        $this->actingAs($this->admin)
            ->patchJson(route('admin.centre-services-it.categories.update', $categorie), [
                'sla_jours_p1' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('sla_jours_p1', 3);

        $this->assertSame(3, $categorie->fresh()->sla_jours_p1);
    }

    public function test_liste_filtre_par_statut_priorite_et_retard(): void
    {
        $ticket = $this->creerTicket(['priorite' => 'P1']);
        $ticket->update(['echeance_sla' => now()->subDay()]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.centre-services-it.tickets.transition', $ticket), [
                'statut' => 'in_progress',
            ])
            ->assertOk();

        $this->actingAs($this->admin)
            ->getJson(route('admin.centre-services-it.tickets.index', ['en_retard' => 1, 'priorite' => 'P1']))
            ->assertOk()
            ->assertJsonPath('total', 1);

        $this->actingAs($this->admin)
            ->getJson(route('admin.centre-services-it.tickets.index', ['statut' => 'completed']))
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_ticket_sans_tache_reste_indisponible_et_non_transitionnable(): void
    {
        $ticket = $this->creerTicket();
        $ticket->update(['task_id' => null]);

        $this->actingAs($this->admin)
            ->getJson(route('admin.centre-services-it.tickets.show', $ticket))
            ->assertOk()
            ->assertJsonPath('statut', 'indisponible');

        $this->actingAs($this->admin)
            ->postJson(route('admin.centre-services-it.tickets.transition', $ticket), [
                'statut' => 'in_progress',
            ])
            ->assertStatus(409);
    }

    /* ── Helpers ── */

    /**
     * @param  array<string, mixed>  $surcharges
     * @return array<string, mixed>
     */
    private function chargeTicket(array $surcharges = []): array
    {
        return array_merge([
            'cle_idempotence' => (string) Str::uuid(),
            'categorie_id' => $this->categorie->id,
            'sujet' => 'Mot de passe bloqué',
            'description' => 'Le compte ne répond plus après trois tentatives.',
            'priorite' => 'P2',
            'faculte_id' => $this->faculteA->id,
        ], $surcharges);
    }

    /**
     * @param  array<string, mixed>  $surcharges
     */
    private function creerTicket(array $surcharges = []): TicketIt
    {
        $reponse = $this->actingAs($this->admin)
            ->postJson(route('admin.centre-services-it.tickets.store'), $this->chargeTicket($surcharges))
            ->assertCreated();

        return TicketIt::with('tache')->findOrFail((int) $reponse->json('id'));
    }

    private function creerUtilisateur(string $role, ?Faculte $faculte = null): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Agent '.$role.' '.Str::random(4),
            'email' => 'loop11.'.Str::random(10).'@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $faculte?->id,
        ], $role);
    }
}
