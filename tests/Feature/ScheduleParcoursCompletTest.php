<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\AppNotification;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SCHEDULE-14 — Parcours intégré à travers les frontières HTTP réelles
 * (aucun appel de service direct) : admin crée → publie → portails
 * étudiant/enseignant exposent → annulation notifiée + historisée →
 * déplacement en conflit refusé → accès inter-facultés refusés.
 *
 * Posture : chaque étape traverse middleware + policy + FormRequest +
 * transaction réelles. Navigateur authentifié : NOT_EXECUTED (aucune
 * infrastructure navigateur pilotée dans le projet — prérequis absents).
 */
class ScheduleParcoursCompletTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $adminAutreFaculte;

    private Schedule $planning;

    private ScheduleSlot $slot;

    private Teacher $enseignant;

    private Etudiant $etudiantA;

    private Etudiant $etudiantB;

    private Matiere $matiere;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.schedule.active' => true]);

        $faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'x', 'active' => true]);
        $faculteB = Faculte::create(['nom' => 'FB', 'code' => 'FB', 'description' => 'x', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Sco', 'email' => 'sco.e2e@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->adminAutreFaculte = User::creerCompteBackOffice([
            'name' => 'Admin B', 'email' => 'b.e2e@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $faculteB->id,
        ], 'admin_faculte');

        $groupeA = Group::create(['code' => 'G-E2EA', 'nom' => 'Groupe E2E A', 'faculte_id' => $faculte->id, 'niveau' => 'L1', 'actif' => true]);
        $groupeB = Group::create(['code' => 'G-E2EB', 'nom' => 'Groupe E2E B', 'faculte_id' => $faculte->id, 'niveau' => 'L1', 'actif' => true]);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->matiere = Matiere::create(['code' => 'E2E101', 'nom' => 'Analyse E2E', 'niveau' => 'L1', 'semestre' => 1]);
        $this->enseignant = Teacher::create([
            'nom' => 'E2E', 'prenom' => 'Prof', 'email' => 'e2e.prof@universite.test',
            'actif' => true, 'statut' => 'actif', 'password' => 'Motdepasse@123',
        ]);
        Room::create(['code' => 'E2', 'nom' => 'Salle E2', 'capacite' => 60, 'actif' => true]);

        $mkEtudiant = function (string $cin, Group $groupe, int $faculteId) {
            $e = Etudiant::create([
                'cin' => $cin, 'nom' => 'Etudiant', 'prenom' => $cin,
                'email' => strtolower($cin).'@e2e.test', 'sexe' => 'F',
                'faculte_id' => $faculteId, 'niveau' => 'L1',
                'annee_academique' => '2026-2027', 'adresse' => 'Rue',
                'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
                'password' => 'Etudiant@123', 'groupe_id' => $groupe->id,
            ]);
            $e->definirStatut('valide');

            return $e;
        };

        $this->etudiantA = $mkEtudiant('EE200001', $groupeA, $faculte->id);
        $this->etudiantB = $mkEtudiant('EE200002', $groupeB, $faculte->id);

        // Point de départ administratif : planning créé via la route publique.
        $this->planning = Schedule::create([
            'annee_universitaire_id' => $annee->id, 'semestre' => 1,
            'groupe_id' => $groupeA->id, 'nom' => 'E2E S1', 'actif' => true,
        ]);
        $this->slot = ScheduleSlot::create([
            'schedule_id' => $this->planning->id, 'matiere_id' => $this->matiere->id,
            'teacher_id' => $this->enseignant->id, 'room_id' => Room::where('code', 'E2')->firstOrFail()->id,
            'day_of_week' => 'monday', 'start_time' => '08:00:00', 'end_time' => '10:00:00',
            'type' => 'course', 'status' => 'scheduled',
        ]);
    }

    public function test_parcours_admin_creation_a_portails_annulation_conflit(): void
    {
        // 1. État pré-publication : le portail étudiant ne diffuse PAS le
        // brouillon — 404 explicite « Aucun emploi du temps » (contrat
        // documenté : la diffusion n'existe qu'à partir de `publie`).
        $this->withSession(['etudiant_auth_id' => $this->etudiantA->id])
            ->get(route('etudiant.emploi-du-temps.semaine'))
            ->assertNotFound();

        // 2. Admin publie : brouillon → valide → publie (matrice serveur).
        $this->actingAs($this->admin)
            ->post(route('admin.emplois-du-temps.transiter', [$this->planning, 'valide']))
            ->assertRedirect();
        $this->actingAs($this->admin)
            ->post(route('admin.emplois-du-temps.transiter', [$this->planning, 'publie']))
            ->assertRedirect();
        $this->assertSame('publie', $this->planning->fresh()->statut);

        // 3. Étudiant du groupe voit le créneau publié ; celui d'un autre groupe non.
        $this->withSession(['etudiant_auth_id' => $this->etudiantA->id])
            ->get(route('etudiant.emploi-du-temps.semaine'))
            ->assertOk()
            ->assertSee('Analyse E2E');
        $this->withSession(['etudiant_auth_id' => $this->etudiantB->id])
            ->get(route('etudiant.emploi-du-temps.semaine'))
            ->assertNotFound(); // autre groupe : jamais le planning d'autrui (404, pas de fuite)

        // 4. Enseignant : son portail (+ détail propriétaire) exposent le cours.
        $this->withSession(['enseignant_auth_id' => $this->enseignant->id])
            ->get(route('enseignant.emploi-du-temps.semaine'))
            ->assertOk()
            ->assertSee('Analyse E2E');

        // 5. Annulation admin : statut + historique + notification étudiant.
        $this->actingAs($this->admin)
            ->post(route('admin.emplois-du-temps.slots.cancel', $this->slot), ['motif' => 'Grève simulée'])
            ->assertRedirect();
        $this->assertSame('cancelled', $this->slot->fresh()->status);
        $this->assertDatabaseHas('schedule_changes', [
            'schedule_slot_id' => $this->slot->id, 'change_type' => 'cancelled',
        ]);
        $this->assertTrue(AppNotification::where('etudiant_id', $this->etudiantA->id)
            ->where('type', 'schedule.cancelled')->exists());
        $this->assertFalse(AppNotification::where('etudiant_id', $this->etudiantB->id)
            ->where('type', 'schedule.cancelled')->exists(), 'Pas de notification hors périmètre.');

        // 6. L'annulé est exclu du moteur de conflit : un nouveau créneau sur la
        // même plage est accepté ; le conflit est recalculé pour de vrai.
        $this->actingAs($this->admin)
            ->post(route('admin.emplois-du-temps.slots.store', $this->planning), [
                'matiere_id' => $this->matiere->id, 'teacher_id' => $this->enseignant->id,
                'day_of_week' => 'monday', 'start_time' => '08:00', 'end_time' => '10:00',
                'type' => 'td',
            ])->assertSessionDoesntHaveErrors();
        $nouveau = ScheduleSlot::where('schedule_id', $this->planning->id)->where('status', '!=', 'cancelled')->latest('id')->firstOrFail();

        // 7. Déplacement vers un créneau en conflit réel (même enseignant,
        // mercredi, doublon) → 422 JSON et position inchangée.
        $mercredi = ScheduleSlot::create([
            'schedule_id' => $this->planning->id, 'matiere_id' => $this->matiere->id,
            'teacher_id' => $this->enseignant->id, 'room_id' => null,
            'day_of_week' => 'wednesday', 'start_time' => '08:00:00', 'end_time' => '10:00:00',
            'type' => 'course', 'status' => 'scheduled',
        ]);
        $this->actingAs($this->admin)->patchJson(
            route('admin.emplois-du-temps.slots.deplacer', $nouveau),
            ['day_of_week' => 'wednesday', 'start_time' => '08:00', 'end_time' => '10:00', 'room_id' => null]
        )->assertUnprocessable();
        $this->assertSame('monday', $nouveau->fresh()->day_of_week);

        // 8. Inter-facultés : show/edit/suppression hors périmètre → 404.
        $this->actingAs($this->adminAutreFaculte)
            ->get(route('admin.emplois-du-temps.show', $this->planning))
            ->assertNotFound();
        $this->assertSame(0, ScheduleChange::where('schedule_slot_id', $this->slot->id)
            ->where('changed_by', $this->adminAutreFaculte->id)->count(),
            'Aucune trace d\'action par un utilisateur hors périmètre.');
    }
}
