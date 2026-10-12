<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\ElementRecent;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Models\VueSauvegardee;
use App\Modules\Attendance\Models\Justification;
use App\Modules\Attendance\Models\Presence;
use App\Modules\OnlineRequests\Models\RequestType;
use App\Modules\OnlineRequests\Services\OnlineRequestService;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Services\TaskGenerationService;
use App\Modules\Tasks\Services\TaskService;
use App\Services\Interface\RecentsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phases 7-9 — Tâches, cockpit, palette, récents, vues : création
 * idempotente, transitions serveur, bulk, périmètres, permissions.
 */
class TasksCockpitTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.tasks.active' => true,
            'modules.attendance.active' => true,
            'modules.online-requests.active' => true,
        ]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.tasks@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
    }

    public function test_module_desactive_repond_404(): void
    {
        config(['modules.tasks.active' => false]);

        $this->actingAs($this->admin)->get(route('admin.taches.index'))->assertNotFound();
    }

    public function test_synchronisation_idempotente_et_transitions(): void
    {
        $service = app(TaskService::class);

        $premiere = $service->synchroniser('demande:1', [
            'titre' => 'Traiter REQ-1', 'source' => 'demande', 'priorite' => 'high',
        ]);
        $seconde = $service->synchroniser('demande:1', ['titre' => 'Traiter REQ-1', 'source' => 'demande']);

        $this->assertSame($premiere->id, $seconde->id);
        $this->assertSame(1, Task::where('cle_unique', 'demande:1')->count());

        $service->transiter($premiere, 'completed', $this->admin->id);
        $this->assertSame('completed', $premiere->fresh()->statut);

        // Réactivation d'une source revenue : pas de doublon.
        $reactivee = $service->synchroniser('demande:1', ['titre' => 'Traiter REQ-1', 'source' => 'demande']);
        $this->assertSame('pending', $reactivee->statut);
        $this->assertSame(1, Task::where('cle_unique', 'demande:1')->count());

        // Transition interdite.
        $this->expectException(\DomainException::class);
        $service->transiter($reactivee->fresh(), 'expired', $this->admin->id);
    }

    public function test_bulk_action_respecte_perimetre(): void
    {
        $autreFaculte = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);

        $mienne = Task::create([
            'titre' => 'Mienne', 'source' => 'manuelle', 'cle_unique' => 'm:1',
            'faculte_id' => $this->faculte->id,
        ]);
        $horsPerimetre = Task::create([
            'titre' => 'Autre', 'source' => 'manuelle', 'cle_unique' => 'm:2',
            'faculte_id' => $autreFaculte->id,
        ]);

        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.tasks@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');

        $this->actingAs($adminFaculte)->post(route('admin.taches.bulk'), [
            'ids' => [$mienne->id, $horsPerimetre->id], 'action' => 'terminer',
        ])->assertSessionHasNoErrors();

        $this->assertSame('completed', $mienne->fresh()->statut);
        $this->assertSame('pending', $horsPerimetre->fresh()->statut);

        // Détail hors périmètre : 404, pas de fuite.
        $this->actingAs($adminFaculte)->get(route('admin.taches.show', $horsPerimetre))->assertNotFound();
    }

    public function test_generation_agrege_emetteurs_et_cloture(): void
    {
        $etudiant = $this->creerEtudiant();
        $presence = Presence::create([
            'etudiant_id' => $etudiant->id, 'date' => '2026-10-06', 'seance' => '08:00', 'statut' => 'absent',
        ]);
        $justification = Justification::create([
            'presence_id' => $presence->id, 'etudiant_id' => $etudiant->id,
            'motif' => 'Motif', 'statut' => 'pending',
        ]);

        $compteurs = app(TaskGenerationService::class)->generer();

        $this->assertSame(1, $compteurs['justifications']);
        $this->assertDatabaseHas('tasks', ['cle_unique' => "justification:{$justification->id}"]);

        // Idempotence : relance sans doublon.
        app(TaskGenerationService::class)->generer();
        $this->assertSame(1, Task::where('cle_unique', "justification:{$justification->id}")->count());

        // Source résolue : clôture système automatique.
        $justification->update(['statut' => 'approved']);
        $compteurs = app(TaskGenerationService::class)->generer();

        $this->assertSame(1, $compteurs['cloturees']);
        $this->assertSame('completed', Task::where('cle_unique', "justification:{$justification->id}")->first()->statut);
    }

    public function test_demande_soumise_cree_tache_via_event(): void
    {
        $etudiant = $this->creerEtudiant();
        $type = RequestType::create(['code' => 'ATT', 'name' => 'Attestation', 'active' => true, 'processing_days' => 2]);

        $service = app(OnlineRequestService::class);
        $demande = $service->creer($etudiant, ['request_type_id' => $type->id, 'objet' => 'Attestation', 'form_data' => []]);
        $service->soumettre($demande);

        $this->assertDatabaseHas('tasks', ['cle_unique' => "demande:{$demande->id}"]);
    }

    public function test_cockpit_scopes_et_permissions(): void
    {
        Task::create(['titre' => 'Urgente', 'source' => 'manuelle', 'cle_unique' => 'c:1', 'priorite' => 'critical']);

        $this->actingAs($this->admin)->get(route('admin.cockpit.index'))->assertOk();

        $consultation = User::creerCompteBackOffice([
            'name' => 'Cons', 'email' => 'cons.tasks@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');

        // Consultation : cockpit lecture OK, centre de tâches masqué par Gate.
        $this->actingAs($consultation)->get(route('admin.cockpit.index'))->assertOk();
    }

    public function test_palette_json_cloisonnee(): void
    {
        $this->creerEtudiant('PAL00001', 'palette.tasks@universite.test');

        $reponse = $this->actingAs($this->admin)->getJson(route('admin.recherche.instantanee', ['q' => 'PAL00001']));
        $reponse->assertOk();
        $this->assertNotEmpty($reponse->json());

        // Requête trop courte : rien.
        $this->actingAs($this->admin)->getJson(route('admin.recherche.instantanee', ['q' => 'x']))
            ->assertJsonCount(0);

        // Sans permission archives : aucun étudiant exposé.
        $agent = User::creerCompteBackOffice([
            'name' => 'Ag', 'email' => 'ag.tasks@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        // L'agent a archives.view : filtrons par absence de demandes.
        $reponse = $this->actingAs($agent)->getJson(route('admin.recherche.instantanee', ['q' => 'PAL00001']));
        $sections = array_column($reponse->json(), 'section');
        $this->assertContains('Étudiants', $sections);
    }

    public function test_recents_plafonnes_et_vues_propres(): void
    {
        $service = app(RecentsService::class);

        for ($i = 1; $i <= 25; $i++) {
            $service->enregistrer($this->admin->id, 'etudiant', (string) $i, "Étudiant {$i}", "/admin/etudiants/{$i}");
        }

        $this->assertSame(20, ElementRecent::where('user_id', $this->admin->id)->count());

        // Vues : propriété stricte.
        $vue = VueSauvegardee::create([
            'user_id' => $this->admin->id, 'tableau' => 'taches', 'nom' => 'Urgentes',
            'filtres' => ['priorite' => 'critical'],
        ]);

        $autre = User::creerCompteBackOffice([
            'name' => 'Autre2', 'email' => 'autre2.tasks@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $this->actingAs($autre)->delete(route('admin.vues.destroy', $vue))->assertNotFound();
        $this->actingAs($this->admin)->delete(route('admin.vues.destroy', $vue))->assertSessionHasNoErrors();

        // Tableau non whitelisté refusé.
        $this->actingAs($this->admin)->post(route('admin.vues.store'), [
            'tableau' => 'users', 'nom' => 'X',
        ])->assertSessionHasErrors('tableau');
    }

    private function creerEtudiant(string $cin = 'EE900001', string $email = 'e.tasks@universite.test'): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara', 'email' => $email,
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => '12 rue des Tests',
            'ville' => 'Casablanca', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        return $etudiant;
    }
}
