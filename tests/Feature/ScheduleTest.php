<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleChange;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Services\ScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Emplois du temps : activation, CRUD, conflits, exceptions,
 * historique, réactivité, widgets.
 */
class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected Faculte $faculte;

    protected User $admin;

    protected Group $groupe;

    protected AnneeUniversitaire $annee;

    protected Matiere $matiere;

    protected Teacher $enseignant;

    protected Room $salle;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.schedule.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences', 'code' => 'FST',
            'description' => 'Sciences', 'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité Test',
            'email' => 'scolarite.test@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->groupe = Group::create([
            'code' => 'L1-INFO-A', 'nom' => 'L1 Info A',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'filiere' => 'Informatique', 'actif' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->matiere = Matiere::create([
            'code' => 'INFO101', 'nom' => 'Algorithmique',
            'coefficient' => 2, 'credits' => 6,
            'niveau' => 'L1', 'semestre' => 1,
        ]);

        $this->enseignant = Teacher::create(['nom' => 'Ben Salah', 'prenom' => 'Ahmed']);
        $this->salle = Room::create(['code' => 'A01', 'capacite' => 60]);
    }

    protected function planning(): Schedule
    {
        return Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'groupe_id' => $this->groupe->id,
            'nom' => 'L1 Info A — S1',
            'actif' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function creneau(string $debut = '08:00', string $fin = '09:30'): array
    {
        return [
            'matiere_id' => $this->matiere->id,
            'teacher_id' => $this->enseignant->id,
            'room_id' => $this->salle->id,
            'day_of_week' => 'monday',
            'start_time' => $debut,
            'end_time' => $fin,
            'type' => 'course',
        ];
    }

    public function test_module_desactive_404(): void
    {
        config(['modules.schedule.active' => false]);

        $this->actingAs($this->admin)->get('/admin/emplois-du-temps')->assertNotFound();
        $this->get('/etudiant/emploi-du-temps')->assertRedirect(route('etudiant.connexion.show'));
    }

    public function test_creation_planning_et_creneau_valide(): void
    {
        $this->actingAs($this->admin)->post(route('admin.emplois-du-temps.store'), [
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'groupe_id' => $this->groupe->id,
            'nom' => 'L1 Info A — S1',
        ])->assertRedirect();

        $planning = Schedule::firstOrFail();

        $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.slots.store', $planning),
            $this->creneau()
        )->assertRedirect();

        $this->assertSame(1, $planning->slots()->count());
        $this->assertDatabaseHas('schedule_changes', ['change_type' => 'created']);
    }

    public function test_conflit_groupe_refuse(): void
    {
        $planning = $this->planning();
        $service = $this->app->make(ScheduleService::class);
        $service->creerCreneau($planning, $this->creneau('10:00', '11:30'));

        $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.slots.store', $planning),
            $this->creneau('11:00', '12:30')
        )->assertSessionHasErrors('conflit');

        $this->assertSame(1, $planning->slots()->count());
    }

    public function test_conflit_enseignant_et_salle_refuses(): void
    {
        $planning = $this->planning();
        $service = $this->app->make(ScheduleService::class);
        $service->creerCreneau($planning, $this->creneau('10:00', '11:30'));

        $autreGroupe = Group::create([
            'code' => 'L1-INFO-B', 'nom' => 'L1 Info B',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'actif' => true,
        ]);
        $autrePlanning = Schedule::create([
            'annee_universitaire_id' => $this->annee->id, 'semestre' => 1,
            'groupe_id' => $autreGroupe->id, 'nom' => 'B', 'actif' => true,
        ]);

        // Même enseignant, autre groupe, chevauchement → refus.
        $resultat = $service->creerCreneau($autrePlanning, $this->creneau('10:30', '12:00'));
        $this->assertArrayHasKey('erreurs', $resultat);

        // Salle différente + enseignant différent → OK.
        $autreEnseignant = Teacher::create(['nom' => 'X', 'prenom' => 'Y']);
        $autreSalle = Room::create(['code' => 'B99']);
        $donnees = $this->creneau('10:30', '12:00');
        $donnees['teacher_id'] = $autreEnseignant->id;
        $donnees['room_id'] = $autreSalle->id;
        $resultat = $service->creerCreneau($autrePlanning, $donnees);
        $this->assertArrayHasKey('slot', $resultat);

        // Même salle qu'ailleurs, chevauchement → refus.
        $donnees['teacher_id'] = $autreEnseignant->id;
        $donnees['room_id'] = $this->salle->id;
        $donnees['start_time'] = '10:15';
        $donnees['end_time'] = '11:00';
        $resultat = $service->creerCreneau($autrePlanning, $donnees);
        $this->assertArrayHasKey('erreurs', $resultat);
    }

    public function test_horaires_invalides_refuses(): void
    {
        $planning = $this->planning();

        $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.slots.store', $planning),
            $this->creneau('10:00', '10:00')
        )->assertSessionHasErrors('end_time');

        $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.slots.store', $planning),
            array_merge($this->creneau(), ['day_of_week' => 'funday'])
        )->assertSessionHasErrors('day_of_week');
    }

    public function test_modification_annulation_restauration_historisees(): void
    {
        $planning = $this->planning();
        $service = $this->app->make(ScheduleService::class);
        $slot = $service->creerCreneau($planning, $this->creneau())['slot'];

        $nouvelleSalle = Room::create(['code' => 'B12']);
        $this->actingAs($this->admin)->patch(
            route('admin.emplois-du-temps.slots.update', $slot),
            ['room_id' => $nouvelleSalle->id, 'motif' => 'Maintenance.']
        )->assertRedirect();

        $this->assertSame('modified', $slot->fresh()->status);
        $this->assertDatabaseHas('schedule_changes', ['change_type' => 'room_changed']);

        $this->actingAs($this->admin)
            ->post(route('admin.emplois-du-temps.slots.cancel', $slot))
            ->assertRedirect();
        $this->assertSame('cancelled', $slot->fresh()->status);

        $this->actingAs($this->admin)
            ->post(route('admin.emplois-du-temps.slots.restore', $slot))
            ->assertRedirect();
        $this->assertSame('scheduled', $slot->fresh()->status);
        $this->assertSame(4, ScheduleChange::where('schedule_slot_id', $slot->id)->count());
    }

    public function test_exception_ponctuelle_et_unicite(): void
    {
        $planning = $this->planning();
        $service = $this->app->make(ScheduleService::class);
        $slot = $service->creerCreneau($planning, $this->creneau())['slot'];

        $date = now()->addWeek()->format('Y-m-d');
        $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.slots.exceptions.store', $slot),
            [
                'exception_date' => $date,
                'new_start_time' => '11:00',
                'new_end_time' => '12:30',
                'status' => 'modified',
                'reason' => 'Maintenance.',
            ]
        )->assertRedirect();

        // Doublon même date → refus métier.
        $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.slots.exceptions.store', $slot),
            ['exception_date' => $date, 'status' => 'cancelled']
        )->assertSessionHasErrors('exception_date');

        $this->assertSame(1, $slot->exceptions()->count());
    }

    public function test_etudiant_voit_son_planning_et_actualisations(): void
    {
        $planning = $this->planning();
        $service = $this->app->make(ScheduleService::class);
        $service->creerCreneau($planning, $this->creneau());

        // SCHEDULE-09 (clôture) : le portail étudiant n'expose que les
        // plannings PUBLIÉS — publication via le workflow réel.
        $service->transiterPlanning($planning, 'valide');
        $service->publierPlanning($planning->fresh());

        $etudiant = Etudiant::create([
            'cin' => 'BK910001', 'nom' => 'Test', 'prenom' => 'Etudiant',
            'email' => 'bk910001@example.com', 'sexe' => 'M',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'Rue',
            'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'motdepasse1', 'groupe_id' => $this->groupe->id,
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-BK910001'])->save();
        $etudiant->definirStatut('valide');

        session(['etudiant_auth_id' => $etudiant->fresh()->id]);

        $this->get(route('etudiant.emploi-du-temps.semaine'))
            ->assertOk()->assertSee('Algorithmique')->assertSee('A01');

        $reponse = $this->getJson(route('etudiant.emploi-du-temps.actualisations', ['depuis' => '0']));
        $reponse->assertOk()->assertJsonPath('updated', true);
        $version = $reponse->json('version');

        // Sans changement : pas de payload.
        $this->getJson(route('etudiant.emploi-du-temps.actualisations', ['depuis' => $version]))
            ->assertOk()->assertJsonPath('updated', false);

        // Après modification (seconde suivante) : détection + nouveau payload.
        $this->travel(2)->seconds();
        $slot = ScheduleSlot::firstOrFail();
        $service->modifierCreneau($slot, ['notes' => 'Salle changée.']);

        $this->getJson(route('etudiant.emploi-du-temps.actualisations', ['depuis' => $version]))
            ->assertOk()->assertJsonPath('updated', true);
    }

    public function test_etudiant_sans_groupe_404(): void
    {
        $etudiant = Etudiant::create([
            'cin' => 'BK910002', 'nom' => 'Sans', 'prenom' => 'Groupe',
            'email' => 'bk910002@example.com', 'sexe' => 'M',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'Rue',
            'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-BK910002'])->save();
        $etudiant->definirStatut('valide');

        session(['etudiant_auth_id' => $etudiant->fresh()->id]);

        $this->get(route('etudiant.emploi-du-temps.semaine'))->assertNotFound();
    }

    public function test_periode_semestre_validee_et_affichee(): void
    {
        $this->actingAs($this->admin)->post(route('admin.emplois-du-temps.store'), [
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'groupe_id' => $this->groupe->id,
            'nom' => 'Test période',
            'starts_at' => '2027-01-31',
            'ends_at' => '2026-09-15',
        ])->assertSessionHasErrors('ends_at');

        $this->actingAs($this->admin)->post(route('admin.emplois-du-temps.store'), [
            'annee_universitaire_id' => $this->annee->id,
            'semestre' => 1,
            'groupe_id' => $this->groupe->id,
            'nom' => 'Test période',
            'starts_at' => '2026-09-15',
            'ends_at' => '2027-01-31',
        ])->assertRedirect();

        $planning = Schedule::where('nom', 'Test période')->firstOrFail();
        $this->assertSame('2026-09-15', $planning->starts_at->format('Y-m-d'));

        $this->actingAs($this->admin)->get(route('admin.emplois-du-temps.show', $planning))
            ->assertOk()->assertSee('15/09/2026');
    }

    public function test_statut_moved_accepte(): void
    {
        $planning = $this->planning();
        $service = $this->app->make(ScheduleService::class);
        $slot = $service->creerCreneau($planning, $this->creneau())['slot'];

        $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.slots.exceptions.store', $slot),
            [
                'exception_date' => now()->addWeek()->format('Y-m-d'),
                'status' => 'moved',
                'reason' => 'Déplacé en amphi.',
            ]
        )->assertRedirect();

        $this->assertSame('moved', $slot->exceptions()->firstOrFail()->status);
    }

    public function test_modification_ponctuelle_ne_touche_pas_le_recurrent(): void
    {
        $planning = $this->planning();
        $service = $this->app->make(ScheduleService::class);
        $slot = $service->creerCreneau($planning, $this->creneau())['slot'];

        $date = now()->addWeek()->format('Y-m-d');
        $this->actingAs($this->admin)->patch(
            route('admin.emplois-du-temps.slots.update', $slot),
            [
                'room_id' => Room::create(['code' => 'Z99'])->id,
                'portee' => 'date',
                'date_ponctuelle' => $date,
                'motif' => 'Travaux.',
            ]
        )->assertRedirect();

        // Créneau récurrent intact, exception créée.
        $this->assertSame('A01', $slot->fresh()->room->code);
        $this->assertSame('scheduled', $slot->fresh()->status);
        $this->assertSame(1, $slot->exceptions()->count());

        // Matière/type/jour refusés en portée date.
        $this->actingAs($this->admin)->patch(
            route('admin.emplois-du-temps.slots.update', $slot),
            [
                'type' => 'tp',
                'portee' => 'date',
                'date_ponctuelle' => now()->addWeeks(2)->format('Y-m-d'),
            ]
        )->assertSessionHasErrors('portee');
    }

    public function test_json_expose_valeurs_normales(): void
    {
        $planning = $this->planning();
        $service = $this->app->make(ScheduleService::class);
        $slot = $service->creerCreneau($planning, $this->creneau())['slot'];
        $service->creerException($slot, [
            'exception_date' => now()->startOfWeek(Carbon::MONDAY)->format('Y-m-d'),
            'new_room_id' => Room::create(['code' => 'B08'])->id,
            'status' => 'modified',
            'reason' => 'Maintenance.',
        ]);

        // SCHEDULE-09 (clôture) : publication via le workflow réel — le
        // portail étudiant n'expose que les plannings publiés.
        $service->transiterPlanning($planning, 'valide');
        $service->publierPlanning($planning->fresh());

        $etudiant = Etudiant::create([
            'cin' => 'BK910003', 'nom' => 'Test', 'prenom' => 'Etudiant',
            'email' => 'bk910003@example.com', 'sexe' => 'M',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'Rue',
            'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'motdepasse1', 'groupe_id' => $this->groupe->id,
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-BK910003'])->save();
        $etudiant->definirStatut('valide');

        session(['etudiant_auth_id' => $etudiant->fresh()->id]);

        $reponse = $this->getJson(route('etudiant.emploi-du-temps.actualisations', ['depuis' => '0']));
        $reponse->assertOk();
        $lundi = collect($reponse->json('slots'))->firstWhere('date', now()->startOfWeek(Carbon::MONDAY)->format('Y-m-d'));

        $this->assertSame('B08', $lundi['salle']);
        $this->assertSame('A01', $lundi['salle_normale']);

        $this->get(route('etudiant.emploi-du-temps.semaine'))
            ->assertOk()->assertSee('Normalement');
    }

    public function test_planning_surligne_aujourdhui_et_prochain_cours(): void
    {
        config(['modules.schedule.active' => true]);
        $this->travelTo(Carbon::parse('next monday 07:00'));

        $planning = $this->planning();
        $service = $this->app->make(ScheduleService::class);
        $service->creerCreneau($planning, $this->creneau('08:00', '09:30'));

        // SCHEDULE-09 (clôture) : publication via le workflow réel — le
        // portail étudiant n'expose que les plannings publiés.
        $service->transiterPlanning($planning, 'valide');
        $service->publierPlanning($planning->fresh());

        $etudiant = Etudiant::create([
            'cin' => 'BK910004', 'nom' => 'Test', 'prenom' => 'Etudiant',
            'email' => 'bk910004@example.com', 'sexe' => 'M',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'Rue',
            'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'motdepasse1', 'groupe_id' => $this->groupe->id,
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-BK910004'])->save();
        $etudiant->definirStatut('valide');

        session(['etudiant_auth_id' => $etudiant->fresh()->id]);

        $this->get(route('etudiant.emploi-du-temps.semaine'))
            ->assertOk()->assertSee('aujourd’hui');

        $this->get(route('etudiant.dashboard'))
            ->assertOk()->assertSee('Prochain cours')->assertSee('Algorithmique');
    }

    public function test_fiche_planning_modale_suppression(): void
    {
        $planning = $this->planning();
        $service = $this->app->make(ScheduleService::class);
        $service->creerCreneau($planning, $this->creneau());

        $this->actingAs($this->admin)->get(route('admin.emplois-du-temps.show', $planning))
            ->assertOk()
            ->assertSee('Supprimer ce créneau ?', false)
            ->assertSee('role="dialog"', false);
    }

    public function test_badges_deplace_et_enseignant_remplace(): void
    {
        $planning = $this->planning();
        $service = $this->app->make(ScheduleService::class);
        $slot = $service->creerCreneau($planning, $this->creneau())['slot'];
        $autre = Teacher::create(['nom' => 'Remplaçant', 'prenom' => 'Samir']);
        $service->creerException($slot, [
            'exception_date' => now()->startOfWeek(Carbon::MONDAY)->format('Y-m-d'),
            'new_teacher_id' => $autre->id,
            'status' => 'moved',
            'reason' => 'Intervention.',
        ]);

        // SCHEDULE-09 (clôture) : publication via le workflow réel — le
        // portail étudiant n'expose que les plannings publiés.
        $service->transiterPlanning($planning, 'valide');
        $service->publierPlanning($planning->fresh());

        $etudiant = Etudiant::create([
            'cin' => 'BK910005', 'nom' => 'Test', 'prenom' => 'Etudiant',
            'email' => 'bk910005@example.com', 'sexe' => 'M',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'Rue',
            'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'motdepasse1', 'groupe_id' => $this->groupe->id,
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-BK910005'])->save();
        $etudiant->definirStatut('valide');

        session(['etudiant_auth_id' => $etudiant->fresh()->id]);

        $this->get(route('etudiant.emploi-du-temps.semaine'))
            ->assertOk()
            ->assertSee('DÉPLACÉ')
            ->assertSee('Enseignant remplacé');
    }
}
