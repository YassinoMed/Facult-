<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleAvailability;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Services\ScheduleExplorerService;
use App\Modules\Schedule\Services\ScheduleGenerationSettings;
use App\Modules\Schedule\Services\ScheduleService;
use App\Services\ParametreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE A (Smart Timetables) : disponibilités/indisponibilités déclarées —
 * CRUD, validation, contraintes dures (conflits créneau), explorer,
 * périmètre faculté, paramètres du moteur de génération.
 */
class ScheduleDisponibilitesRessourcesTest extends TestCase
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

        $this->enseignant = Teacher::create(['nom' => 'Ben Salah', 'prenom' => 'Ahmed', 'actif' => true]);
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
    protected function creneau(string $debut = '09:00', string $fin = '10:00'): array
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

    /**
     * @param  array<string, mixed>  $surcharges
     */
    protected function plageIndisponible(array $surcharges = []): ScheduleAvailability
    {
        return ScheduleAvailability::create($surcharges + [
            'teacher_id' => $this->enseignant->id,
            'day_of_week' => 'monday',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'type' => 'indisponible',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function donneesHttp(): array
    {
        return [
            'teacher_id' => $this->enseignant->id,
            'day_of_week' => 'monday',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'type' => 'indisponible',
            'reason' => 'Réunion de faculté',
        ];
    }

    public function test_module_desactive_404(): void
    {
        config(['modules.schedule.active' => false]);

        $this->actingAs($this->admin)
            ->get('/admin/emplois-du-temps/indisponibilites')
            ->assertNotFound();
    }

    public function test_invite_redirige_vers_connexion(): void
    {
        $this->get(route('admin.emplois-du-temps.indisponibilites.index'))
            ->assertRedirect(route('admin.connexion.show'));
    }

    public function test_index_reserve_permission_view_et_liste_les_plages(): void
    {
        $this->plageIndisponible();

        $consultant = User::creerCompteBackOffice([
            'name' => 'Consultation', 'email' => 'consultation@universite.test',
            'password' => 'Motdepasse@123',
        ], 'consultation');

        $this->actingAs($consultant)
            ->get(route('admin.emplois-du-temps.indisponibilites.index'))
            ->assertOk()
            ->assertSee('Ben Salah')
            ->assertSee('Indisponible');
    }

    public function test_consultation_sans_manage_ne_peut_pas_creer(): void
    {
        $consultant = User::creerCompteBackOffice([
            'name' => 'Consultation', 'email' => 'consultation@universite.test',
            'password' => 'Motdepasse@123',
        ], 'consultation');

        $this->actingAs($consultant)
            ->post(route('admin.emplois-du-temps.indisponibilites.store'), $this->donneesHttp())
            ->assertForbidden();

        $this->assertSame(0, ScheduleAvailability::count());
    }

    public function test_creation_via_http_indisponibilite_enseignant(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.emplois-du-temps.indisponibilites.store'), $this->donneesHttp())
            ->assertRedirect(route('admin.emplois-du-temps.indisponibilites.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('schedule_availabilities', [
            'teacher_id' => $this->enseignant->id,
            'day_of_week' => 'monday',
            'type' => 'indisponible',
            'reason' => 'Réunion de faculté',
            'cree_par' => $this->admin->id,
        ]);

        // Traçabilité d'intégration (aucune donnée personnelle).
        $this->assertDatabaseHas('integration_logs', [
            'integration' => 'schedule',
            'operation' => 'SCHEDULE_AVAILABILITY_CREATED',
            'status' => 'completed',
        ]);
    }

    public function test_validation_exactement_une_ressource(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.emplois-du-temps.indisponibilites.store'), [
                'day_of_week' => 'monday', 'start_time' => '08:00', 'end_time' => '12:00',
                'type' => 'indisponible',
            ])
            ->assertSessionHasErrors('teacher_id');

        $this->assertSame(0, ScheduleAvailability::count());

        // Deux ressources renseignées : refusé aussi.
        $this->actingAs($this->admin)
            ->post(route('admin.emplois-du-temps.indisponibilites.store'), array_merge($this->donneesHttp(), [
                'room_id' => $this->salle->id,
            ]))
            ->assertSessionHasErrors('teacher_id');
    }

    public function test_validation_heure_fin_avant_debut(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.emplois-du-temps.indisponibilites.store'), array_merge($this->donneesHttp(), [
                'start_time' => '12:00', 'end_time' => '08:00',
            ]))
            ->assertSessionHasErrors('end_time');

        $this->assertSame(0, ScheduleAvailability::count());
    }

    public function test_chevauchement_meme_ressource_refuse_et_adjacence_autorisee(): void
    {
        $this->plageIndisponible();

        // Chevauchement même ressource + même type : refusé.
        $this->actingAs($this->admin)
            ->post(route('admin.emplois-du-temps.indisponibilites.store'), array_merge($this->donneesHttp(), [
                'start_time' => '11:00', 'end_time' => '13:00',
            ]))
            ->assertSessionHasErrors('conflit');

        $this->assertSame(1, ScheduleAvailability::count());

        // Plage adjacente (12:00 → 13:00) : autorisée.
        $this->actingAs($this->admin)
            ->post(route('admin.emplois-du-temps.indisponibilites.store'), array_merge($this->donneesHttp(), [
                'start_time' => '12:00', 'end_time' => '13:00',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, ScheduleAvailability::count());
    }

    public function test_modification_et_suppression_via_http(): void
    {
        $plage = $this->plageIndisponible();

        $this->actingAs($this->admin)
            ->patch(route('admin.emplois-du-temps.indisponibilites.update', $plage), array_merge($this->donneesHttp(), [
                'end_time' => '14:00', 'type' => 'souhaitee',
            ]))
            ->assertRedirect(route('admin.emplois-du-temps.indisponibilites.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('schedule_availabilities', [
            'id' => $plage->id, 'end_time' => '14:00', 'type' => 'souhaitee',
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.emplois-du-temps.indisponibilites.destroy', $plage))
            ->assertRedirect(route('admin.emplois-du-temps.indisponibilites.index'));

        $this->assertDatabaseMissing('schedule_availabilities', ['id' => $plage->id]);
    }

    public function test_indisponibilite_enseignant_bloque_creation_creneau(): void
    {
        $this->plageIndisponible();
        $planning = $this->planning();
        $service = app(ScheduleService::class);

        // Lundi 09:00 → 10:00 : chevauche 08:00 → 12:00 → refusé.
        $resultat = $service->creerCreneau($planning, $this->creneau('09:00', '10:00'));
        $this->assertArrayHasKey('erreurs', $resultat);
        $this->assertStringContainsString('indisponible', implode(' ', $resultat['erreurs']));
        $this->assertSame(0, ScheduleSlot::count());

        // Lundi 13:00 → 14:00 : hors plage → autorisé.
        $resultat = $service->creerCreneau($planning, $this->creneau('13:00', '14:00'));
        $this->assertArrayHasKey('slot', $resultat);

        // Mardi : hors plage → autorisé.
        $resultat = $service->creerCreneau($planning, array_merge($this->creneau('09:00', '10:00'), [
            'day_of_week' => 'tuesday',
        ]));
        $this->assertArrayHasKey('slot', $resultat);

        // Lundi sans enseignant : la plage enseignant ne s'applique pas.
        $resultat = $service->creerCreneau($planning, array_merge($this->creneau('09:00', '10:00'), [
            'teacher_id' => null,
        ]));
        $this->assertArrayHasKey('slot', $resultat);

        $this->assertSame(3, ScheduleSlot::count());
    }

    public function test_indisponibilite_salle_bloque_creation_creneau(): void
    {
        $this->plageIndisponible([
            'teacher_id' => null,
            'room_id' => $this->salle->id,
        ]);
        $planning = $this->planning();

        $resultat = app(ScheduleService::class)->creerCreneau($planning, $this->creneau('09:00', '10:00'));
        $this->assertArrayHasKey('erreurs', $resultat);
        $this->assertStringContainsString('Salle déclarée indisponible', implode(' ', $resultat['erreurs']));

        // Sans salle : autorisé.
        $resultat = app(ScheduleService::class)->creerCreneau($planning, array_merge($this->creneau('09:00', '10:00'), [
            'room_id' => null,
        ]));
        $this->assertArrayHasKey('slot', $resultat);
    }

    public function test_indisponibilite_groupe_bloque_creation_creneau(): void
    {
        $this->plageIndisponible([
            'teacher_id' => null,
            'group_id' => $this->groupe->id,
        ]);
        $planning = $this->planning();

        // Même sans enseignant ni salle : le groupe est indisponible.
        $resultat = app(ScheduleService::class)->creerCreneau($planning, array_merge($this->creneau('09:00', '10:00'), [
            'teacher_id' => null, 'room_id' => null,
        ]));
        $this->assertArrayHasKey('erreurs', $resultat);
        $this->assertStringContainsString('Groupe déclaré indisponible', implode(' ', $resultat['erreurs']));
    }

    public function test_plage_souhaitee_non_bloquante(): void
    {
        $this->plageIndisponible(['type' => 'souhaitee']);
        $planning = $this->planning();

        $resultat = app(ScheduleService::class)->creerCreneau($planning, $this->creneau('09:00', '10:00'));
        $this->assertArrayHasKey('slot', $resultat);
    }

    public function test_portee_annee_autre_annee_ne_bloque_pas(): void
    {
        $autreAnnee = AnneeUniversitaire::create([
            'libelle' => '2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
            'active' => false,
        ]);

        $this->plageIndisponible(['annee_universitaire_id' => $autreAnnee->id]);
        $planning = $this->planning(); // Année 2026-2027

        $resultat = app(ScheduleService::class)->creerCreneau($planning, $this->creneau('09:00', '10:00'));
        $this->assertArrayHasKey('slot', $resultat);
    }

    public function test_fenetre_effective_hors_periode_ne_bloque_pas(): void
    {
        $planning = $this->planning();
        $planning->update(['starts_at' => '2027-01-04', 'ends_at' => '2027-06-30']);

        // Fenêtre effective 2025 : ne recouvre pas la période du planning.
        $this->plageIndisponible([
            'effective_from' => '2025-01-01', 'effective_to' => '2025-12-31',
        ]);

        $resultat = app(ScheduleService::class)->creerCreneau($planning, $this->creneau('09:00', '10:00'));
        $this->assertArrayHasKey('slot', $resultat);

        // Fenêtre effective 2027 : recouvre → refusé.
        $this->plageIndisponible([
            'effective_from' => '2027-02-01', 'effective_to' => '2027-03-31',
        ]);

        $resultat = app(ScheduleService::class)->creerCreneau($planning, $this->creneau('10:00', '11:00'));
        $this->assertArrayHasKey('erreurs', $resultat);
    }

    public function test_creneaux_libres_excluent_les_indisponibilites(): void
    {
        $this->plageIndisponible(); // Enseignant lundi 08:00 → 12:00
        $this->planning();

        $libres = app(ScheduleExplorerService::class)
            ->creneauxLibres($this->groupe->id, $this->enseignant->id, 60, null);

        $lundi = collect($libres)->where('jour', 'monday')->pluck('debut')->all();

        $this->assertNotContains('08:00', $lundi);
        $this->assertNotContains('11:30', $lundi);
        $this->assertContains('12:00', $lundi);
    }

    public function test_disponibilites_explorer_marque_indisponible(): void
    {
        $this->plageIndisponible();

        $dispos = app(ScheduleExplorerService::class)->disponibilites('monday', '08:00', '09:00', null);
        $enseignant = collect($dispos['enseignants'])->firstWhere('id', $this->enseignant->id);

        $this->assertNotNull($enseignant);
        $this->assertFalse($enseignant['libre']);
        $this->assertTrue($enseignant['indisponible']);
        $this->assertSame('lundi 08:00–12:00', $enseignant['indisponible_par']);
    }

    public function test_verification_deplacement_signale_indisponibilite(): void
    {
        $this->plageIndisponible();
        $planning = $this->planning();
        $slot = ScheduleSlot::create([
            'schedule_id' => $planning->id, 'matiere_id' => $this->matiere->id,
            'teacher_id' => $this->enseignant->id, 'room_id' => $this->salle->id,
            'day_of_week' => 'monday', 'start_time' => '14:00', 'end_time' => '15:00',
            'type' => 'course', 'status' => 'scheduled',
        ]);

        $reponse = $this->actingAs($this->admin)->post(
            route('admin.emplois-du-temps.slots.verifier', $slot),
            ['day_of_week' => 'monday', 'start_time' => '09:00', 'end_time' => '10:00'],
        );

        $reponse->assertOk()->assertJsonFragment(['disponible' => false]);

        $conflits = $reponse->json('conflits');
        $this->assertTrue(
            collect($conflits)->contains(fn ($conflit) => str_contains($conflit, 'indisponible')),
            'Le conflit d’indisponibilité déclarée doit être signalé.',
        );
    }

    public function test_suppression_leve_la_contrainte(): void
    {
        $plage = $this->plageIndisponible();
        $planning = $this->planning();
        $service = app(ScheduleService::class);

        $this->assertArrayHasKey('erreurs', $service->creerCreneau($planning, $this->creneau('09:00', '10:00')));

        $this->actingAs($this->admin)
            ->delete(route('admin.emplois-du-temps.indisponibilites.destroy', $plage))
            ->assertRedirect();

        $this->assertArrayHasKey('slot', $service->creerCreneau($planning, $this->creneau('09:00', '10:00')));
    }

    public function test_admin_faculte_perimetre_restreint(): void
    {
        $autreFaculte = Faculte::create([
            'nom' => 'Faculté des Lettres', 'code' => 'FLSH',
            'description' => 'Lettres', 'active' => true,
        ]);
        $groupeAutre = Group::create([
            'code' => 'L1-LETTRES-A', 'nom' => 'L1 Lettres A',
            'faculte_id' => $autreFaculte->id, 'niveau' => 'L1',
            'filiere' => 'Lettres', 'actif' => true,
        ]);
        $plageAutre = ScheduleAvailability::create([
            'group_id' => $groupeAutre->id,
            'day_of_week' => 'monday', 'start_time' => '08:00', 'end_time' => '12:00',
            'type' => 'indisponible',
        ]);
        $plageEnseignant = $this->plageIndisponible();

        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Admin Faculté', 'email' => 'af.fst@universite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');

        // Index : la plage enseignant (transversale) est visible, la plage
        // du groupe hors faculté est masquée.
        $this->actingAs($adminFaculte)
            ->get(route('admin.emplois-du-temps.indisponibilites.index'))
            ->assertOk()
            ->assertSee('Ben Salah')
            ->assertDontSee('L1-LETTRES-A');

        // Édition d'une plage hors périmètre : 404.
        $this->actingAs($adminFaculte)
            ->get(route('admin.emplois-du-temps.indisponibilites.edit', $plageAutre))
            ->assertNotFound();

        // Requête forgée visant un groupe hors faculté : 404.
        $this->actingAs($adminFaculte)
            ->post(route('admin.emplois-du-temps.indisponibilites.store'), [
                'group_id' => $groupeAutre->id,
                'day_of_week' => 'monday', 'start_time' => '08:00', 'end_time' => '12:00',
                'type' => 'indisponible',
            ])
            ->assertNotFound();

        // Plage enseignant : gérée par l'admin restreint (transversale).
        $this->actingAs($adminFaculte)
            ->get(route('admin.emplois-du-temps.indisponibilites.edit', $plageEnseignant))
            ->assertOk();
    }

    public function test_parametres_generation_defauts_surs(): void
    {
        $this->assertDatabaseHas('parametres', [
            'cle' => 'planning.generation.poids_trous', 'categorie' => 'planning',
        ]);

        $parametres = app(ScheduleGenerationSettings::class);

        $this->assertSame(5, $parametres->poidsTrous());
        $this->assertSame(5, $parametres->poidsEquilibreJours());
        $this->assertSame(3, $parametres->poidsContinuite());
        $this->assertSame(240, $parametres->dureeMaxSequenceMinutes());
        $this->assertSame(50000, $parametres->iterationMax());
    }

    public function test_parametres_generation_bornes_appliquees(): void
    {
        $service = app(ParametreService::class);
        $service->set('planning.generation.poids_trous', '999');
        $service->set('planning.generation.poids_equilibre_jours', 'abc');
        $service->set('planning.generation.duree_max_sequence_minutes', '5');

        $parametres = app(ScheduleGenerationSettings::class);

        // Borné au maximum ; valeur non numérique (cast entier = 0) →
        // préférence désactivée sans casser le moteur ; borné au minimum.
        $this->assertSame(10, $parametres->poidsTrous());
        $this->assertSame(0, $parametres->poidsEquilibreJours());
        $this->assertSame(30, $parametres->dureeMaxSequenceMinutes());
    }
}
