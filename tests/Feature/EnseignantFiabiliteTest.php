<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Attendance\Models\TeacherPointage;
use App\Modules\Enseignement\Models\AffectationEnseignement;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Enseignement\Models\TypeEnseignement;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\JuryMembre;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Evaluation\Services\EpreuveService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase A — Fiabilité et sécurité du module Enseignants.
 * Suppression gardée (jamais de 500), autorisations CRUD, throttles.
 */
class EnseignantFiabiliteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $lecteur;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Matiere $matiere;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.fiab@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->lecteur = User::creerCompteBackOffice([
            'name' => 'Lecture', 'email' => 'lecteur.fiab@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->matiere = Matiere::create([
            'code' => 'FIAB101', 'nom' => 'Fiabilité', 'niveau' => 'L1', 'semestre' => 1,
        ]);
    }

    private function creerEnseignant(string $suffixe): Teacher
    {
        return Teacher::create([
            'nom' => 'Nom'.$suffixe, 'prenom' => 'Prenom'.$suffixe,
            'email' => "fiab{$suffixe}@universite.test", 'actif' => true, 'statut' => 'actif',
        ]);
    }

    private function creerCreneau(Teacher $enseignant): ScheduleSlot
    {
        $groupe = Group::create([
            'code' => 'G-FIAB-'.uniqid(), 'nom' => 'Groupe', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'actif' => true,
        ]);
        $planning = Schedule::create([
            'annee_universitaire_id' => $this->annee->id, 'semestre' => 1,
            'groupe_id' => $groupe->id, 'nom' => 'Planning',
        ]);

        return ScheduleSlot::create([
            'schedule_id' => $planning->id, 'matiere_id' => $this->matiere->id,
            'teacher_id' => $enseignant->id, 'day_of_week' => 'monday',
            'start_time' => '08:00:00', 'end_time' => '10:00:00',
        ]);
    }

    private function creerAffectationEnseignement(Teacher $enseignant): void
    {
        $type = TypeEnseignement::create(['code' => 'CM', 'nom' => 'Cours magistral']);
        $enseignement = Enseignement::create([
            'matiere_id' => $this->matiere->id, 'type_id' => $type->id,
            'annee_universitaire_id' => $this->annee->id,
        ]);
        AffectationEnseignement::create([
            'enseignement_id' => $enseignement->id, 'teacher_id' => $enseignant->id,
        ]);
    }

    private function creerSurveillance(Teacher $enseignant): void
    {
        $programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LFIAB', 'nom' => 'Licence',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create(['programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $groupe = Group::create([
            'code' => 'G-SURV-'.uniqid(), 'nom' => 'Groupe', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'actif' => true,
        ]);
        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id, 'code' => 'S-FIAB',
            'nom' => 'Principale', 'type' => 'principale',
            'date_debut' => '2027-01-05', 'date_fin' => '2027-06-30', 'statut' => 'open',
            'created_by' => $this->admin->id,
        ]);
        $examen = Examen::create([
            'session_id' => $session->id, 'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen', 'type' => 'examen', 'statut' => 'scheduled',
            'responsable_id' => $this->admin->id,
        ]);
        $epreuve = app(EpreuveService::class)->creer($examen, [
            'session_id' => $session->id, 'matiere_id' => $this->matiere->id,
            'groupe_id' => $groupe->id, 'date' => '2027-03-10',
            'heure_debut' => '09:00', 'heure_fin' => '11:00',
        ]);
        Surveillance::create([
            'epreuve_id' => $epreuve->id, 'teacher_id' => $enseignant->id,
            'role' => 'surveillant', 'statut' => 'assigne',
        ]);
        $this->assertTrue($niveau->exists);
        $this->assertInstanceOf(Epreuve::class, $epreuve);
    }

    public function test_suppression_avec_creneau_refusee_sans_500(): void
    {
        $enseignant = $this->creerEnseignant('A');
        $this->creerCreneau($enseignant);

        $reponse = $this->actingAs($this->admin)->delete(route('admin.enseignants.destroy', $enseignant));

        $reponse->assertRedirect()->assertSessionHasErrors('enseignant');
        $this->assertDatabaseHas('teachers', ['id' => $enseignant->id]);
    }

    public function test_suppression_avec_affectation_enseignement_refusee_sans_500(): void
    {
        $enseignant = $this->creerEnseignant('B');
        $this->creerAffectationEnseignement($enseignant);

        $reponse = $this->actingAs($this->admin)->delete(route('admin.enseignants.destroy', $enseignant));

        $reponse->assertRedirect()->assertSessionHasErrors('enseignant');
        $this->assertDatabaseHas('teachers', ['id' => $enseignant->id]);
        $this->assertDatabaseHas('affectations_enseignement', ['teacher_id' => $enseignant->id]);
    }

    public function test_suppression_avec_surveillance_refusee_sans_500(): void
    {
        $enseignant = $this->creerEnseignant('C');
        $this->creerSurveillance($enseignant);

        $reponse = $this->actingAs($this->admin)->delete(route('admin.enseignants.destroy', $enseignant));

        $reponse->assertRedirect()->assertSessionHasErrors('enseignant');
        $this->assertDatabaseHas('teachers', ['id' => $enseignant->id]);
        $this->assertDatabaseHas('evaluation_surveillants', ['teacher_id' => $enseignant->id]);
    }

    public function test_suppression_avec_pointage_refusee_sans_500(): void
    {
        $enseignant = $this->creerEnseignant('D');
        TeacherPointage::create([
            'teacher_id' => $enseignant->id, 'date' => '2026-11-03',
            'horodatage' => '2026-11-03 08:02:00',
        ]);

        $reponse = $this->actingAs($this->admin)->delete(route('admin.enseignants.destroy', $enseignant));

        $reponse->assertRedirect()->assertSessionHasErrors('enseignant');
        $this->assertDatabaseHas('teachers', ['id' => $enseignant->id]);
        $this->assertDatabaseHas('teacher_pointages', ['teacher_id' => $enseignant->id]);
    }

    public function test_suppression_avec_jury_ou_matiere_refusee_sans_500(): void
    {
        $enseignant = $this->creerEnseignant('G');
        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id, 'statut' => 'draft',
            'created_by' => $this->admin->id,
        ]);
        JuryMembre::create(['deliberation_id' => $deliberation->id, 'teacher_id' => $enseignant->id]);
        Matiere::create([
            'code' => 'FIAB102', 'nom' => 'Jury', 'niveau' => 'L1', 'semestre' => 1,
            'responsable_teacher_id' => $enseignant->id,
        ]);

        $reponse = $this->actingAs($this->admin)->delete(route('admin.enseignants.destroy', $enseignant));

        $reponse->assertRedirect()->assertSessionHasErrors('enseignant');
        $this->assertDatabaseHas('teachers', ['id' => $enseignant->id]);
        $this->assertDatabaseHas('evaluation_jury_membres', ['teacher_id' => $enseignant->id]);
        $this->assertDatabaseHas('matieres', ['responsable_teacher_id' => $enseignant->id]);
    }

    public function test_suppression_sans_attache_reussit(): void
    {
        $enseignant = $this->creerEnseignant('E');

        $this->actingAs($this->admin)->delete(route('admin.enseignants.destroy', $enseignant))
            ->assertRedirect(route('admin.enseignants.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('teachers', ['id' => $enseignant->id]);
    }

    public function test_index_exige_permission_lecture(): void
    {
        // Invité : redirection vers la connexion (jamais de contenu).
        $this->get(route('admin.enseignants.index'))->assertRedirect();

        // Consultation (enseignants.view) : lecture autorisée.
        $this->actingAs($this->lecteur)->get(route('admin.enseignants.index'))->assertOk();

        // Contrat : la route porte le Gate (garde anti-régression M1).
        $this->assertContains(
            'can:enseignants.view',
            Route::getRoutes()->getByName('admin.enseignants.index')->gatherMiddleware()
        );
    }

    public function test_ecritures_reservees_au_manage(): void
    {
        $enseignant = $this->creerEnseignant('F');

        // Invité : rien ne fuit, tout redirige.
        $this->get(route('admin.enseignants.create'))->assertRedirect();
        $this->post(route('admin.enseignants.store'), ['nom' => 'X', 'prenom' => 'Y'])->assertRedirect();

        // Consultation : lecture OK, écritures 403, rien en base.
        $this->actingAs($this->lecteur)->get(route('admin.enseignants.create'))->assertForbidden();
        $this->actingAs($this->lecteur)->get(route('admin.enseignants.edit', $enseignant))->assertForbidden();
        $this->actingAs($this->lecteur)->post(route('admin.enseignants.store'), ['nom' => 'X', 'prenom' => 'Y'])
            ->assertForbidden();
        $this->actingAs($this->lecteur)->put(route('admin.enseignants.update', $enseignant), ['nom' => 'X', 'prenom' => 'Y'])
            ->assertForbidden();
        $this->actingAs($this->lecteur)->delete(route('admin.enseignants.destroy', $enseignant))
            ->assertForbidden();
        $this->assertDatabaseMissing('teachers', ['nom' => 'X']);
        $this->assertDatabaseHas('teachers', ['id' => $enseignant->id]);

        // Contrat middlewares des écritures.
        foreach (['admin.enseignants.store', 'admin.enseignants.update', 'admin.enseignants.destroy'] as $nom) {
            $middlewares = Route::getRoutes()->getByName($nom)->gatherMiddleware();
            $this->assertContains('can:enseignants.manage', $middlewares, $nom);
            $this->assertContains('throttle:20,1', $middlewares, $nom);
        }
    }

    public function test_store_throttle(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->actingAs($this->admin)->post(route('admin.enseignants.store'), [
                'nom' => 'Throttle'.$i, 'prenom' => 'Test',
            ])->assertRedirect();
        }

        // 21e requête dans la minute : 429 (jamais d'écriture).
        $this->actingAs($this->admin)->post(route('admin.enseignants.store'), [
            'nom' => 'Throttle21', 'prenom' => 'Test',
        ])->assertStatus(429);
        $this->assertDatabaseMissing('teachers', ['nom' => 'Throttle21']);
    }
}
