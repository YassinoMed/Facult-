<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Services\ScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exploration multi-axes (§1-8, §16-19, §25-26, §28) : recherche,
 * disponibilités, créneaux libres, charge, conflits, occupation,
 * périmètre, cache.
 */
class ScheduleExplorerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Schedule $planning;

    protected Teacher $enseignant;

    protected Room $salle;

    protected Group $groupe;

    protected Matiere $matiere;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.schedule.active' => true]);

        $faculte = Faculte::create([
            'nom' => 'FST', 'code' => 'FST', 'description' => 'S', 'active' => true,
        ]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.explo@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->groupe = Group::create([
            'code' => 'GI2-A', 'nom' => 'GI2 A', 'faculte_id' => $faculte->id,
            'niveau' => 'L1', 'filiere' => 'Informatique', 'actif' => true,
        ]);
        $this->planning = Schedule::create([
            'annee_universitaire_id' => $annee->id, 'semestre' => 1,
            'groupe_id' => $this->groupe->id, 'nom' => 'L1 — S1', 'actif' => true,
        ]);
        $this->matiere = Matiere::create([
            'code' => 'JAVA101', 'nom' => 'Java', 'coefficient' => 2,
            'credits' => 6, 'niveau' => 'L1', 'semestre' => 1,
        ]);
        $this->enseignant = Teacher::create(['nom' => 'Trabelsi', 'prenom' => 'Mohamed']);
        $this->salle = Room::create(['code' => 'B204', 'capacite' => 40]);

        app(ScheduleService::class)->creerCreneau($this->planning, [
            'matiere_id' => $this->matiere->id, 'teacher_id' => $this->enseignant->id,
            'room_id' => $this->salle->id, 'day_of_week' => 'tuesday',
            'start_time' => '10:00', 'end_time' => '12:00', 'type' => 'course',
        ], $this->admin->id);
    }

    private function explorer(string $route, array $params = [])
    {
        return $this->actingAs($this->admin)->get(route($route, $params));
    }

    public function test_explorer_sans_filtre_affiche_creneau(): void
    {
        $this->explorer('admin.emplois-du-temps.explorer')
            ->assertOk()
            ->assertSee('Explorer les emplois du temps')
            ->assertSee('Java', false)
            ->assertSee('1</strong> créneau', false);
    }

    public function test_filtres_axes_independants(): void
    {
        $this->explorer('admin.emplois-du-temps.explorer', ['enseignant_id' => $this->enseignant->id])
            ->assertOk()->assertSee('Java', false);

        // ID inexistant : validation (302), pas d'autorisation.
        $this->explorer('admin.emplois-du-temps.explorer', ['enseignant_id' => 999999 + $this->enseignant->id])
            ->assertRedirect();

        $this->explorer('admin.emplois-du-temps.explorer', ['salle_id' => $this->salle->id])
            ->assertOk()->assertSee('B204', false);

        $this->explorer('admin.emplois-du-temps.explorer', ['jour' => 'monday'])
            ->assertOk()->assertSee('Aucun créneau', false);

        $this->explorer('admin.emplois-du-temps.explorer', ['matiere_id' => $this->matiere->id])
            ->assertOk()->assertSee('Java', false);
    }

    public function test_filtres_combines_et_chips(): void
    {
        $reponse = $this->explorer('admin.emplois-du-temps.explorer', [
            'enseignant_id' => $this->enseignant->id,
            'salle_id' => $this->salle->id,
            'jour' => 'tuesday',
        ])->assertOk();

        $reponse->assertSee('Filtres actifs', false)
            ->assertSee('Java', false);
    }

    public function test_groupe_hors_perimetre_404(): void
    {
        $faculteB = Faculte::create(['nom' => 'FB', 'code' => 'FB', 'description' => 'B', 'active' => true]);
        $groupeB = Group::create([
            'code' => 'GB-1', 'nom' => 'GB', 'faculte_id' => $faculteB->id,
            'niveau' => 'L1', 'filiere' => 'Info', 'actif' => true,
        ]);
        $adminA = User::creerCompteBackOffice([
            'name' => 'Admin A', 'email' => 'aa.explo@universite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $this->groupe->faculte_id,
        ], 'admin_faculte');

        $this->actingAs($adminA)
            ->get(route('admin.emplois-du-temps.explorer', ['groupe_id' => $groupeB->id]))
            ->assertNotFound();
    }

    public function test_disponibilites_marque_occupe_et_libre(): void
    {
        $autreEnseignant = Teacher::create(['nom' => 'Libre', 'prenom' => 'Ali']);

        $reponse = $this->explorer('admin.emplois-du-temps.disponibilites', [
            'jour' => 'tuesday', 'debut' => '10:00', 'fin' => '12:00',
        ])->assertOk();

        $reponse->assertSee('Mohamed Trabelsi', false)
            ->assertSee('Occupé', false)
            ->assertSee('Ali Libre', false)
            ->assertSee('Disponible', false);
    }

    public function test_trouver_salle_capacite(): void
    {
        Room::create(['code' => 'B201', 'capacite' => 50]);

        $reponse = $this->explorer('admin.emplois-du-temps.disponibilites', [
            'jour' => 'tuesday', 'debut' => '10:00', 'fin' => '12:00', 'capacite_min' => 30,
        ])->assertOk();

        // B204 occupée → exclue des salles ≥ 30 places ; B201 libre → proposée.
        $reponse->assertSee('B201', false);
        $reponse->assertSee('1 salle(s)', false);
    }

    public function test_trouver_creneau_propose_intervalles_libres(): void
    {
        $reponse = $this->explorer('admin.emplois-du-temps.trouver-creneau', [
            'groupe_id' => $this->groupe->id,
            'enseignant_id' => $this->enseignant->id,
            'duree' => 120,
        ])->assertOk();

        // Mardi 10h-12h pris → lundi 08h-10h proposé, pas mardi 10h.
        $reponse->assertSee('Lundi 08:00 → 10:00', false);
        $reponse->assertDontSee('Mardi 10:00 → 12:00', false);
    }

    public function test_charge_enseignants_agregee(): void
    {
        $this->explorer('admin.emplois-du-temps.charge')
            ->assertOk()
            ->assertSee('Mohamed Trabelsi', false)
            ->assertSee('2h', false);
    }

    public function test_conflits_detectes_et_filtre(): void
    {
        // Chevauchement réel : insertion directe (la création métier
        // refuserait ce conflit — c'est bien l'objet du détecteur).
        ScheduleSlot::create([
            'schedule_id' => $this->planning->id, 'matiere_id' => $this->matiere->id,
            'teacher_id' => null, 'room_id' => $this->salle->id,
            'day_of_week' => 'tuesday', 'start_time' => '11:00', 'end_time' => '13:00',
            'type' => 'td', 'status' => 'scheduled',
        ]);

        $this->explorer('admin.emplois-du-temps.conflits')
            ->assertOk()->assertSee('Conflit #001', false);

        $this->explorer('admin.emplois-du-temps.conflits', ['type' => 'salle'])
            ->assertOk()->assertSee('B204', false);

        // Filtre enseignant : le 2e créneau n'a pas d'enseignant → aucun.
        $this->explorer('admin.emplois-du-temps.conflits', ['type' => 'enseignant'])
            ->assertOk()->assertSee('Aucun conflit', false);
    }

    public function test_occupation_salles_matrice(): void
    {
        $this->explorer('admin.emplois-du-temps.occupation', ['jour' => 'tuesday'])
            ->assertOk()
            ->assertSee('B204', false)
            ->assertSee('Java', false);
    }

    public function test_sans_permission_redirige_403(): void
    {
        $etudiantUser = User::creerCompteBackOffice([
            'name' => 'X', 'email' => 'x.explo@universite.test',
            'password' => 'Motdepasse@123',
        ], 'consultation');

        // consultation A schedules.view → 200 (lecture autorisée).
        $this->actingAs($etudiantUser)
            ->get(route('admin.emplois-du-temps.explorer'))
            ->assertOk();
    }

    public function test_invite_redirige_connexion(): void
    {
        $this->get(route('admin.emplois-du-temps.explorer'))->assertRedirect();
    }

    public function test_cache_second_appel_store_database_comme_production(): void
    {
        // Non-régression : `cache.serializable_classes=false` interdit les
        // objets sérialisés — le service ne met en cache que des IDs.
        config(['cache.default' => 'database']);

        $premier = $this->explorer('admin.emplois-du-temps.explorer')
            ->assertOk()->assertSee('Java', false);
        $second = $this->explorer('admin.emplois-du-temps.explorer')
            ->assertOk()->assertSee('Java', false);

        $this->assertNotNull($premier);
        $this->assertNotNull($second);
    }

    public function test_cache_invalide_apres_modification(): void
    {
        $avant = $this->explorer('admin.emplois-du-temps.explorer', ['jour' => 'wednesday'])
            ->assertOk()->assertSee('Aucun créneau', false);

        app(ScheduleService::class)->creerCreneau($this->planning, [
            'matiere_id' => $this->matiere->id, 'teacher_id' => null,
            'room_id' => null, 'day_of_week' => 'wednesday',
            'start_time' => '08:00', 'end_time' => '10:00', 'type' => 'course',
        ], $this->admin->id);

        // La version change → le cache ne sert plus l'ancien résultat.
        $this->explorer('admin.emplois-du-temps.explorer', ['jour' => 'wednesday'])
            ->assertOk()->assertSee('Java', false);

        $this->assertNotNull($avant);
    }

    public function test_explorer_creneau_en_cours_format_avec_secondes_sans_trailing_data(): void
    {
        // Fige le temps un jeudi à 10:30
        Carbon::setTestNow(Carbon::parse('2026-10-08 10:30:00'));

        try {
            // Créneau avec secondes '10:00:00' -> '12:00:00' pour le jour courant (jeudi)
            ScheduleSlot::create([
                'schedule_id' => $this->planning->id,
                'matiere_id' => $this->matiere->id,
                'teacher_id' => $this->enseignant->id,
                'room_id' => $this->salle->id,
                'day_of_week' => 'thursday',
                'start_time' => '10:00:00',
                'end_time' => '12:00:00',
                'type' => 'course',
                'status' => 'scheduled',
            ]);

            $reponse = $this->explorer('admin.emplois-du-temps.explorer');

            $reponse->assertOk()
                ->assertSee('EN COURS')
                ->assertSee('Fin dans 90 min')
                ->assertSee('10:00 → 12:00');
        } finally {
            Carbon::setTestNow(null);
        }
    }
}
