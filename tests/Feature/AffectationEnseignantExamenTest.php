<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Evaluation\Models\SurveillanceAssignmentRun;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Tests fonctionnels et de sécurité pour la gestion de l'affectation des enseignants aux examens.
 */
class AffectationEnseignantExamenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private SessionExamen $session;

    private Examen $examen;

    private Matiere $matiere;

    private Room $salle;

    private Group $groupe;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.evaluation.active' => true]);

        $this->faculteA = Faculte::create(['nom' => 'Faculté des Sciences', 'code' => 'FST', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'Faculté de Droit', 'code' => 'FDL', 'active' => true]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Scolarité',
            'email' => 'admin.affectation@univ.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'code' => 'L-INFO',
            'nom' => 'Licence Informatique',
            'faculte_id' => $this->faculteA->id,
            'cycle' => 'licence',
            'duree_annees' => 3,
            'actif' => true,
        ]);

        $this->groupe = Group::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'INFO-G1',
            'nom' => 'Groupe 1',
            'niveau' => 'L1',
            'actif' => true,
        ]);

        $this->matiere = Matiere::create([
            'code' => 'ALGO-1',
            'nom' => 'Algorithmique & Structures',
            'niveau' => 'L1',
            'semestre' => 1,
            'coefficient' => 2.0,
            'credits_ects' => 4,
            'volume_horaire_cm' => 20,
        ]);

        $this->salle = Room::create([
            'code' => 'AMPHI-A',
            'nom' => 'Grand Amphithéâtre A',
            'capacite' => 150,
            'faculte_id' => $this->faculteA->id,
            'actif' => true,
        ]);

        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-AFF-2027',
            'nom' => 'Session Janvier 2027',
            'type' => 'principale',
            'statut' => 'open',
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-20',
        ]);

        $this->examen = Examen::create([
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'libelle' => 'Examens Semestre 1',
            'type' => 'examen',
            'statut' => 'scheduled',
        ]);
    }

    private function creerEpreuve(array $attributs = []): Epreuve
    {
        return Epreuve::create(array_merge([
            'annee_universitaire_id' => $this->annee->id,
            'session_id' => $this->session->id,
            'examen_id' => $this->examen->id,
            'matiere_id' => $this->matiere->id,
            'groupe_id' => $this->groupe->id,
            'salle_id' => $this->salle->id,
            'code' => 'EPR-'.uniqid(),
            'date' => '2027-01-12',
            'heure_debut' => '09:00:00',
            'heure_fin' => '11:00:00',
            'duree_minutes' => 120,
            'type_evaluation' => 'examen',
            'coefficient' => 2.0,
            'bareme' => 20.0,
            'note_max' => 20.0,
            'capacite_necessaire' => 80,
            'statut' => 'scheduled',
        ], $attributs));
    }

    private function creerEnseignant(array $attributs = []): Teacher
    {
        $dep = Departement::create([
            'nom' => 'Département Informatique',
            'code' => 'DEP-'.uniqid(),
            'faculte_id' => $this->faculteA->id,
            'actif' => true,
        ]);

        return Teacher::create(array_merge([
            'departement_id' => $dep->id,
            'nom' => 'Ben Salah',
            'prenom' => 'Ahmed',
            'email' => 'prof.'.uniqid().'@univ.test',
            'statut' => 'titulaire',
            'actif' => true,
        ], $attributs));
    }

    // =========================================================================
    // 1. NAVIGATION & SIDEBAR
    // =========================================================================

    public function test_sidebar_affiche_entree_affectation_avec_permission(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.evaluations.index'));

        $response->assertOk();
        $response->assertSee('Affectation enseignants');
        $response->assertSee(route('admin.evaluations.affectations'));
    }

    public function test_sidebar_masque_entree_sans_permission(): void
    {
        Gate::define('epreuves.view', fn () => false);

        $response = $this->actingAs($this->admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertDontSee(route('admin.evaluations.affectations'));
    }

    public function test_route_affectations_active_sur_la_page(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.evaluations.affectations'));

        $response->assertOk();
        $response->assertSee('admin-nav-lien-actif');
        $response->assertSee('Affectation des enseignants');
    }

    // =========================================================================
    // 2. PAGE PRINCIPALE, KPIS & FILTRES
    // =========================================================================

    public function test_page_affectations_affiche_kpis_et_tableau(): void
    {
        $epreuve = $this->creerEpreuve(['code' => 'EPR-INFO-101']);
        $prof = $this->creerEnseignant();

        // 1 affectation existante
        Surveillance::create([
            'epreuve_id' => $epreuve->id,
            'teacher_id' => $prof->id,
            'role' => 'principal',
            'statut' => 'assigne',
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.evaluations.affectations'));

        $response->assertOk();
        $response->assertSee('Examens');
        $response->assertSee('Sans enseignant');
        $response->assertSee('Affectés');
        $response->assertSee('Enseignants dispos');
        $response->assertSee('Affectations');
        $response->assertSee('Conflits détectés');

        $response->assertSee('EPR-INFO-101');
        $response->assertSee('Algorithmique & Structures');
        $response->assertSee('Ahmed Ben Salah');
        $response->assertSee('Lancer l\'affectation automatique', false);
        $response->assertSee('Optimiser les affectations');
        $response->assertSee('modaleConfirmationAuto');
        $response->assertSee('Suivi des traitements');
    }

    public function test_filtres_multicriteres_et_recherche_globale(): void
    {
        $epr1 = $this->creerEpreuve(['code' => 'EPR-ALGO-2027']);
        $autreMatiere = Matiere::create([
            'code' => 'BDD-1',
            'nom' => 'Bases de Données',
            'niveau' => 'L2',
            'semestre' => 2,
            'coefficient' => 2.0,
            'credits_ects' => 4,
            'volume_horaire_cm' => 20,
        ]);
        $epr2 = $this->creerEpreuve(['code' => 'EPR-BDD-2027', 'matiere_id' => $autreMatiere->id]);

        // Recherche par mot-clé "ALGO"
        $resRecherche = $this->actingAs($this->admin)->get(route('admin.evaluations.affectations', ['q' => 'ALGO']));
        $resRecherche->assertSee('EPR-ALGO-2027');
        $resRecherche->assertDontSee('EPR-BDD-2027');

        // Filtre par niveau L2
        $resNiveau = $this->actingAs($this->admin)->get(route('admin.evaluations.affectations', ['niveau' => 'L2']));
        $resNiveau->assertSee('EPR-BDD-2027');
        $resNiveau->assertDontSee('EPR-ALGO-2027');
    }

    // =========================================================================
    // 3. AFFECTATION MANUELLE & RÈGLES MÉTIER
    // =========================================================================

    public function test_affectation_manuelle_reussie(): void
    {
        $epreuve = $this->creerEpreuve();
        $prof = $this->creerEnseignant();

        $response = $this->actingAs($this->admin)->post(route('admin.evaluations.affectations.assigner', $epreuve), [
            'teacher_id' => $prof->id,
            'role' => 'principal',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('succes');

        $this->assertDatabaseHas('evaluation_surveillants', [
            'epreuve_id' => $epreuve->id,
            'teacher_id' => $prof->id,
            'role' => 'principal',
            'statut' => 'assigne',
        ]);
    }

    public function test_affectation_enseignant_inactif_refusee(): void
    {
        $epreuve = $this->creerEpreuve();
        $profInactif = $this->creerEnseignant(['actif' => false]);

        $response = $this->actingAs($this->admin)->post(route('admin.evaluations.affectations.assigner', $epreuve), [
            'teacher_id' => $profInactif->id,
            'role' => 'adjoint',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('affectation');
        $this->assertStringContainsString('inactif', session('errors')->first('affectation'));

        $this->assertDatabaseMissing('evaluation_surveillants', [
            'epreuve_id' => $epreuve->id,
            'teacher_id' => $profInactif->id,
        ]);
    }

    public function test_affectation_enseignant_inexistant_refusee(): void
    {
        $epreuve = $this->creerEpreuve();

        $response = $this->actingAs($this->admin)->post(route('admin.evaluations.affectations.assigner', $epreuve), [
            'teacher_id' => 99999,
            'role' => 'adjoint',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('teacher_id');
    }

    public function test_affectation_conflit_horaire_autre_examen_refusee(): void
    {
        $prof = $this->creerEnseignant();

        // Épreuve 1 : Mardi 12 Janvier 09:00 -> 11:00
        $epreuve1 = $this->creerEpreuve([
            'date' => '2027-01-12',
            'heure_debut' => '09:00:00',
            'heure_fin' => '11:00:00',
        ]);
        Surveillance::create([
            'epreuve_id' => $epreuve1->id,
            'teacher_id' => $prof->id,
            'role' => 'principal',
            'statut' => 'assigne',
        ]);

        // Épreuve 2 : Même créneau ou chevauchement (10:00 -> 12:00)
        $epreuve2 = $this->creerEpreuve([
            'date' => '2027-01-12',
            'heure_debut' => '10:00:00',
            'heure_fin' => '12:00:00',
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.evaluations.affectations.assigner', $epreuve2), [
            'teacher_id' => $prof->id,
            'role' => 'adjoint',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('affectation');
        $this->assertStringContainsString('Surveillant déjà affecté', session('errors')->first('affectation'));

        $this->assertDatabaseMissing('evaluation_surveillants', [
            'epreuve_id' => $epreuve2->id,
            'teacher_id' => $prof->id,
        ]);
    }

    public function test_affectation_conflit_emploi_du_temps_schedule_slot_refusee(): void
    {
        $prof = $this->creerEnseignant();

        // Cours régulier le Mardi 09:00 -> 11:00
        $schedule = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'groupe_id' => $this->groupe->id,
            'semestre' => 1,
            'nom' => 'EDT S1',
            'statut' => 'publie',
            'actif' => true,
        ]);
        ScheduleSlot::create([
            'schedule_id' => $schedule->id,
            'matiere_id' => $this->matiere->id,
            'teacher_id' => $prof->id,
            'day_of_week' => 'tuesday',
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        // Épreuve le Mardi 12 Janvier 2027 à 09:30 -> 11:30
        $epreuve = $this->creerEpreuve([
            'date' => '2027-01-12', // Mardi
            'heure_debut' => '09:30:00',
            'heure_fin' => '11:30:00',
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.evaluations.affectations.assigner', $epreuve), [
            'teacher_id' => $prof->id,
            'role' => 'principal',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('affectation');
        $this->assertStringContainsString('cours régulier', session('errors')->first('affectation'));
    }

    public function test_affectation_deuxieme_principal_refusee(): void
    {
        $epreuve = $this->creerEpreuve();
        $prof1 = $this->creerEnseignant(['email' => 'p1@univ.test']);
        $prof2 = $this->creerEnseignant(['email' => 'p2@univ.test']);

        Surveillance::create([
            'epreuve_id' => $epreuve->id,
            'teacher_id' => $prof1->id,
            'role' => 'principal',
            'statut' => 'assigne',
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.evaluations.affectations.assigner', $epreuve), [
            'teacher_id' => $prof2->id,
            'role' => 'principal',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('affectation');
        $this->assertStringContainsString('Principal est déjà affecté', session('errors')->first('affectation'));
    }

    // =========================================================================
    // 4. RETRAIT / DÉSAFFECTATION
    // =========================================================================

    public function test_retirer_affectation_reussie(): void
    {
        $epreuve = $this->creerEpreuve();
        $prof = $this->creerEnseignant();

        $surv = Surveillance::create([
            'epreuve_id' => $epreuve->id,
            'teacher_id' => $prof->id,
            'role' => 'adjoint',
            'statut' => 'assigne',
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.evaluations.affectations.retirer', $surv));

        $response->assertRedirect();
        $response->assertSessionHas('succes');

        $this->assertSame('annule', $surv->fresh()->statut);
    }

    // =========================================================================
    // 5. AFFECTATION AUTOMATIQUE ET OPTIMISATION
    // =========================================================================

    public function test_affectation_automatique_par_lot(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->creerEpreuve(['code' => "EPR-AUTO-{$i}", 'date' => '2027-01-'.(11 + $i)]);
        }
        for ($p = 0; $p < 10; $p++) {
            $this->creerEnseignant(['email' => "auto_{$p}@univ.test"]);
        }

        $response = $this->actingAs($this->admin)->post(route('admin.evaluations.affectations.auto'), [
            'session_id' => $this->session->id,
        ]);

        // Voie asynchrone : un suivi est créé puis le Job s'exécute (inline
        // sous driver sync de test) avant la redirection vers le suivi.
        $response->assertRedirect(route('admin.evaluations.affectations.runs', ['run' => SurveillanceAssignmentRun::firstOrFail()->uuid]));
        $response->assertSessionHas('succes');
        $this->assertStringContainsString('arrière-plan', session('succes'));

        $run = SurveillanceAssignmentRun::firstOrFail();
        $this->assertContains($run->status, [
            SurveillanceAssignmentRun::STATUS_COMPLETED,
            SurveillanceAssignmentRun::STATUS_COMPLETED_WITH_WARNINGS,
        ]);
        $this->assertSame(3, $run->total_epreuves);

        $totalAssignes = Surveillance::whereIn('statut', ['assigne', 'confirme'])->count();
        $this->assertGreaterThanOrEqual(6, $totalAssignes, 'Chaque épreuve doit avoir au moins 2 actifs.');

        // Session correcte : toutes les affectations créées portent sur des
        // épreuves de la session demandée, et chaque épreuve a son Principal.
        $epreuveIds = Epreuve::where('session_id', $this->session->id)->pluck('id')->all();
        $this->assertNotEmpty($epreuveIds);
        $horsSession = Surveillance::whereIn('statut', ['assigne', 'confirme'])
            ->whereNotIn('epreuve_id', $epreuveIds)
            ->count();
        $this->assertSame(0, $horsSession, 'Aucune affectation hors session demandée.');
        foreach ($epreuveIds as $epreuveId) {
            $this->assertDatabaseHas('evaluation_surveillants', [
                'epreuve_id' => $epreuveId,
                'role' => 'principal',
                'statut' => 'assigne',
            ]);
        }
    }

    public function test_auto_sans_permission_403_sans_modification_db(): void
    {
        $userReadOnly = User::creerCompteBackOffice([
            'name' => 'Lecteur Auto',
            'email' => 'lecteur.auto@univ.test',
            'password' => 'Pass@123',
        ], 'consultation');

        $this->creerEpreuve(['code' => 'EPR-AUTO-403']);
        for ($p = 0; $p < 5; $p++) {
            $this->creerEnseignant(['email' => "auto403_{$p}@univ.test"]);
        }
        $avant = Surveillance::whereIn('statut', ['assigne', 'confirme'])->count();

        $response = $this->actingAs($userReadOnly)->post(route('admin.evaluations.affectations.auto'), [
            'session_id' => $this->session->id,
        ]);

        $response->assertForbidden();
        $this->assertSame($avant, Surveillance::whereIn('statut', ['assigne', 'confirme'])->count());
    }

    public function test_auto_session_sans_epreuves_erreur_metier_et_rollback(): void
    {
        // Session existante mais vide : erreur métier, rien en base.
        $sessionVide = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-VIDE-2027',
            'nom' => 'Session vide',
            'type' => 'principale',
            'statut' => 'open',
            'date_debut' => '2027-02-01',
            'date_fin' => '2027-02-10',
        ]);
        for ($p = 0; $p < 5; $p++) {
            $this->creerEnseignant(['email' => "autovide_{$p}@univ.test"]);
        }
        $avant = Surveillance::whereIn('statut', ['assigne', 'confirme'])->count();

        $response = $this->actingAs($this->admin)->post(route('admin.evaluations.affectations.auto'), [
            'session_id' => $sessionVide->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('affectation');
        $this->assertSame($avant, Surveillance::whereIn('statut', ['assigne', 'confirme'])->count());
    }

    public function test_auto_sans_session_id_message_utilisateur(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.evaluations.affectations.auto'), []);

        $response->assertRedirect();
        $response->assertSessionHasErrors('session_id');
        $this->assertStringContainsString(
            'Veuillez sélectionner une session',
            (string) session('errors')->first('session_id')
        );

        // Le message est réellement affiché sur la page (pas silencieux) :
        // on suit la redirection comme un navigateur.
        $page = $this->actingAs($this->admin)->followingRedirects()->post(route('admin.evaluations.affectations.auto'), []);
        $page->assertOk();
        $page->assertSee('Veuillez sélectionner une session');
    }

    public function test_auto_session_invalide_retourne_erreur_et_rollback(): void
    {
        $avant = Surveillance::whereIn('statut', ['assigne', 'confirme'])->count();

        $response = $this->actingAs($this->admin)->post(route('admin.evaluations.affectations.auto'), [
            'session_id' => 999999,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('session_id');
        $this->assertSame($avant, Surveillance::whereIn('statut', ['assigne', 'confirme'])->count());
    }

    public function test_optimiser_session_invalide_retourne_erreur(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.evaluations.affectations.optimiser'), [
            'session_id' => 999999,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('session_id');
    }

    public function test_boutons_affectations_rendent_formulaires_post_et_selection_session(): void
    {
        $this->creerEpreuve();

        $response = $this->actingAs($this->admin)->get(route('admin.evaluations.affectations'));
        $response->assertOk();

        // Lancement via fetch JSON (bouton « Lancer » + modale de confirmation)
        $response->assertSee(route('admin.evaluations.affectations.auto'), false);
        $response->assertSee(route('admin.evaluations.affectations.optimiser'), false);
        $response->assertSee('csrf-token', false);
        $response->assertSee('modaleConfirmationAuto');
        $response->assertSee('Confirmation', false);

        // Sélecteur de session présent dans l'en-tête
        $response->assertSee('header_session_id');
        $response->assertSee('Lancer l\'affectation automatique', false);
        $response->assertSee('Optimiser les affectations');
        $response->assertSee('Suivi des traitements');
    }

    public function test_optimiser_sans_permission_403(): void
    {
        $userReadOnly = User::creerCompteBackOffice([
            'name' => 'Lecteur Opti',
            'email' => 'lecteur.opti@univ.test',
            'password' => 'Pass@123',
        ], 'consultation');

        $epreuve = $this->creerEpreuve(['code' => 'EPR-OPT-403']);
        $prof = $this->creerEnseignant(['email' => 'opti403@univ.test']);
        Surveillance::create(['epreuve_id' => $epreuve->id, 'teacher_id' => $prof->id, 'role' => 'principal', 'statut' => 'assigne']);
        $avant = Surveillance::whereIn('statut', ['assigne', 'confirme'])->count();

        $response = $this->actingAs($userReadOnly)->post(route('admin.evaluations.affectations.optimiser'), [
            'session_id' => $this->session->id,
        ]);

        $response->assertForbidden();
        $this->assertSame($avant, Surveillance::whereIn('statut', ['assigne', 'confirme'])->count());
    }

    public function test_optimiser_ne_viole_aucune_contrainte(): void
    {
        // 3 épreuves à dates différentes, enseignants + 1 inactif + 1 cours EDT.
        $e1 = $this->creerEpreuve(['code' => 'EPR-CTR-1', 'date' => '2027-01-11', 'heure_debut' => '09:00:00', 'heure_fin' => '11:00:00']);
        $e2 = $this->creerEpreuve(['code' => 'EPR-CTR-2', 'date' => '2027-01-12', 'heure_debut' => '09:00:00', 'heure_fin' => '11:00:00']);
        $e3 = $this->creerEpreuve(['code' => 'EPR-CTR-3', 'date' => '2027-01-13', 'heure_debut' => '09:00:00', 'heure_fin' => '11:00:00']);
        $surcharge = $this->creerEnseignant(['email' => 'ctr_surch@univ.test']);
        $inactif = $this->creerEnseignant(['email' => 'ctr_inactif@univ.test', 'actif' => false]);
        for ($i = 0; $i < 8; $i++) {
            $this->creerEnseignant(['email' => "ctr_{$i}@univ.test"]);
        }

        // Cours régulier mardi 12/01 pour le 1er enseignant dispo (conflit EDT réel).
        $premierDispo = Teacher::where('actif', true)->where('email', 'like', 'ctr_%')->firstOrFail();
        $planning = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'groupe_id' => $this->groupe->id,
            'semestre' => 1,
            'nom' => 'EDT CTR',
            'statut' => 'publie',
            'actif' => true,
        ]);
        ScheduleSlot::create([
            'schedule_id' => $planning->id,
            'matiere_id' => $this->matiere->id,
            'teacher_id' => $premierDispo->id,
            'day_of_week' => 'tuesday',
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        foreach ([$e1, $e2, $e3] as $epreuve) {
            Surveillance::create(['epreuve_id' => $epreuve->id, 'teacher_id' => $surcharge->id, 'role' => 'principal', 'statut' => 'assigne']);
            Surveillance::create(['epreuve_id' => $epreuve->id, 'teacher_id' => $this->creerEnseignant(['email' => 'ctr_a_'.uniqid().'@univ.test'])->id, 'role' => 'adjoint', 'statut' => 'assigne']);
            Surveillance::create(['epreuve_id' => $epreuve->id, 'teacher_id' => $this->creerEnseignant(['email' => 'ctr_r_'.uniqid().'@univ.test'])->id, 'role' => 'reserve', 'statut' => 'assigne']);
        }

        $response = $this->actingAs($this->admin)->post(route('admin.evaluations.affectations.optimiser'), [
            'session_id' => $this->session->id,
        ]);
        $response->assertRedirect();
        $response->assertSessionHas('succes');

        // Aucune contrainte violée après optimisation.
        $actives = Surveillance::whereIn('statut', ['assigne', 'confirme'])->with('epreuve')->get();

        // 1. Aucun enseignant inactif affecté.
        $idsInactifs = Teacher::where('actif', false)->pluck('id')->all();
        foreach ($actives as $s) {
            $this->assertNotContains($s->teacher_id, $idsInactifs, 'Enseignant inactif affecté.');
        }

        // 2. Unicité : un seul Principal actif par épreuve, pas de doublon enseignant.
        foreach ([$e1->id, $e2->id, $e3->id] as $epreuveId) {
            $surEp = $actives->where('epreuve_id', $epreuveId);
            $this->assertSame(1, $surEp->where('role', 'principal')->count(), "Un seul principal sur l'épreuve {$epreuveId}.");
            $this->assertSame($surEp->pluck('teacher_id')->unique()->count(), $surEp->count(), 'Pas de doublon enseignant.');
        }

        // 3. Aucun conflit horaire entre épreuves (même enseignant, créneaux chevauchants).
        foreach ($actives->pluck('teacher_id')->unique() as $teacherId) {
            $gardes = $actives->where('teacher_id', $teacherId)->values();
            for ($a = 0; $a < $gardes->count(); $a++) {
                for ($b = $a + 1; $b < $gardes->count(); $b++) {
                    $ea = $gardes[$a]->epreuve;
                    $eb = $gardes[$b]->epreuve;
                    if ($ea->date->format('Y-m-d') === $eb->date->format('Y-m-d')
                        && (string) $ea->heure_debut < (string) $eb->heure_fin
                        && (string) $eb->heure_debut < (string) $ea->heure_fin) {
                        $this->fail("Conflit horaire pour l'enseignant {$teacherId}.");
                    }
                }
            }
        }

        // 4. Aucune garde sur le créneau EDT réel (mardi 12/01 09-11).
        $gardesMardi = $actives->filter(fn ($s) => $s->epreuve->date->format('Y-m-d') === '2027-01-12');
        foreach ($gardesMardi as $s) {
            $this->assertNotSame($premierDispo->id, (int) $s->teacher_id, 'Conflit EDT : enseignant en cours affecté.');
        }

        // 5. Faculté : rien hors périmètre (toutes les gardes restent sur la session).
        $epreuveIds = Epreuve::where('session_id', $this->session->id)->pluck('id')->all();
        foreach ($actives as $s) {
            $this->assertContains($s->epreuve_id, $epreuveIds);
        }
    }

    public function test_optimiser_isolation_faculte_aucune_modification_hors_perimetre(): void
    {
        $adminFacB = User::creerCompteBackOffice([
            'name' => 'Admin Droit Opti',
            'email' => 'admin.droit.opti@univ.test',
            'password' => 'Pass@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');

        // Épreuves fac A couvertes.
        $epreuve = $this->creerEpreuve(['code' => 'EPR-FACA-OPT']);
        $prof = $this->creerEnseignant();
        Surveillance::create(['epreuve_id' => $epreuve->id, 'teacher_id' => $prof->id, 'role' => 'principal', 'statut' => 'assigne']);
        $avant = Surveillance::where('epreuve_id', $epreuve->id)->whereIn('statut', ['assigne', 'confirme'])->count();

        $response = $this->actingAs($adminFacB)->post(route('admin.evaluations.affectations.optimiser'), [
            'session_id' => $this->session->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('affectation');
        $this->assertSame($avant, Surveillance::where('epreuve_id', $epreuve->id)->whereIn('statut', ['assigne', 'confirme'])->count());
    }

    public function test_auto_creneau_jour_exact_non_bloquant(): void
    {
        // Non-régression : un cours du LUNDI ne bloque pas une épreuve du MERCREDI.
        $epLundi = $this->creerEpreuve(['code' => 'EPR-LUN', 'date' => '2027-01-11', 'heure_debut' => '09:00:00', 'heure_fin' => '11:00:00']);
        $epMerc = $this->creerEpreuve(['code' => 'EPR-MER', 'date' => '2027-01-13', 'heure_debut' => '09:00:00', 'heure_fin' => '11:00:00']);
        $p1 = $this->creerEnseignant(['email' => 'jour.p1@univ.test']);
        $p2 = $this->creerEnseignant(['email' => 'jour.p2@univ.test']);
        $p3 = $this->creerEnseignant(['email' => 'jour.p3@univ.test']);
        $p4 = $this->creerEnseignant(['email' => 'jour.p4@univ.test']);
        $planning = Schedule::create([
            'annee_universitaire_id' => $this->annee->id,
            'groupe_id' => $this->groupe->id,
            'semestre' => 1,
            'nom' => 'EDT jour',
            'statut' => 'publie',
            'actif' => true,
        ]);
        ScheduleSlot::create([
            'schedule_id' => $planning->id,
            'matiere_id' => $this->matiere->id,
            'teacher_id' => $p1->id,
            'day_of_week' => 'monday',
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.evaluations.affectations.auto'), [
            'session_id' => $this->session->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('succes');

        // p1 mobilisé le mercredi (aucun vrai conflit), jamais le lundi.
        $this->assertDatabaseHas('evaluation_surveillants', ['epreuve_id' => $epMerc->id, 'teacher_id' => $p1->id, 'statut' => 'assigne']);
        $this->assertDatabaseMissing('evaluation_surveillants', ['epreuve_id' => $epLundi->id, 'teacher_id' => $p1->id, 'statut' => 'assigne']);
    }

    public function test_optimiser_affectations_session(): void
    {
        // Session déjà couverte mais déséquilibrée : l'optimisation doit
        // DÉPLACER réellement des affectations existantes.
        $e1 = $this->creerEpreuve(['code' => 'EPR-OPT-1', 'date' => '2027-01-11']);
        $e2 = $this->creerEpreuve(['code' => 'EPR-OPT-2', 'date' => '2027-01-12']);
        $surcharge = $this->creerEnseignant(['email' => 'opti_surch@univ.test']);
        $leger = $this->creerEnseignant(['email' => 'opti_leger@univ.test']);
        for ($i = 0; $i < 4; $i++) {
            $this->creerEnseignant(['email' => "opti_{$i}@univ.test"]);
        }
        foreach ([$e1, $e2] as $epreuve) {
            Surveillance::create(['epreuve_id' => $epreuve->id, 'teacher_id' => $surcharge->id, 'role' => 'principal', 'statut' => 'assigne']);
            Surveillance::create(['epreuve_id' => $epreuve->id, 'teacher_id' => $this->creerEnseignant(['email' => 'opti_a_'.uniqid().'@univ.test'])->id, 'role' => 'adjoint', 'statut' => 'assigne']);
            Surveillance::create(['epreuve_id' => $epreuve->id, 'teacher_id' => $this->creerEnseignant(['email' => 'opti_r_'.uniqid().'@univ.test'])->id, 'role' => 'reserve', 'statut' => 'assigne']);
        }
        $chargeAvant = Surveillance::where('teacher_id', $surcharge->id)->whereIn('statut', ['assigne', 'confirme'])->count();
        $this->assertSame(2, $chargeAvant);

        $response = $this->actingAs($this->admin)->post(route('admin.evaluations.affectations.optimiser'), [
            'session_id' => $this->session->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('succes');
        $this->assertStringContainsString('Optimisation globale appliquée', session('succes'));
        $this->assertStringContainsString('améliorée', session('succes'));

        // Rééquilibrage réel : le surchargé a perdu au moins une garde.
        $chargeApres = Surveillance::where('teacher_id', $surcharge->id)->whereIn('statut', ['assigne', 'confirme'])->count();
        $this->assertLessThan($chargeAvant, $chargeApres);
    }

    // =========================================================================
    // 6. SÉCURITÉ, RBAC & ISOLATION FACULTÉ (IDOR)
    // =========================================================================

    public function test_acces_refuse_utilisateur_non_autorise(): void
    {
        $simpleUser = User::factory()->create();

        $response = $this->actingAs($simpleUser)->get(route('admin.evaluations.affectations'));
        $response->assertForbidden();
    }

    public function test_assigner_action_refusee_sans_permission_surveillances_assign(): void
    {
        $userReadOnly = User::creerCompteBackOffice([
            'name' => 'Lecteur Scolarité',
            'email' => 'lecteur@univ.test',
            'password' => 'Pass@123',
        ], 'consultation'); // possède epreuves.view mais pas surveillances.assign

        $epreuve = $this->creerEpreuve();
        $prof = $this->creerEnseignant();

        $response = $this->actingAs($userReadOnly)->post(route('admin.evaluations.affectations.assigner', $epreuve), [
            'teacher_id' => $prof->id,
            'role' => 'principal',
        ]);

        $response->assertForbidden();
    }

    public function test_isolation_faculte_epreuve_autre_faculte_404(): void
    {
        // Administrateur restreint à la Faculté B
        $adminFacB = User::creerCompteBackOffice([
            'name' => 'Admin Droit',
            'email' => 'admin.droit@univ.test',
            'password' => 'Pass@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');

        // Épreuve appartenant à la Faculté A
        $epreuveFacA = $this->creerEpreuve();
        $prof = $this->creerEnseignant();

        $response = $this->actingAs($adminFacB)->post(route('admin.evaluations.affectations.assigner', $epreuveFacA), [
            'teacher_id' => $prof->id,
            'role' => 'principal',
        ]);

        $response->assertNotFound(); // BOLA/IDOR bloqué
    }

    // =========================================================================
    // 7. PERFORMANCE & ABSENCE DE N+1
    // =========================================================================

    public function test_performance_absence_n_plus_un_sur_page_principale(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $epr = $this->creerEpreuve(['code' => "EPR-PERF-{$i}", 'date' => '2027-01-'.sprintf('%02d', 11 + ($i % 5))]);
            $prof = $this->creerEnseignant(['email' => "perf_{$i}@univ.test"]);
            Surveillance::create([
                'epreuve_id' => $epr->id,
                'teacher_id' => $prof->id,
                'role' => 'principal',
                'statut' => 'assigne',
            ]);
        }

        DB::enableQueryLog();

        $response = $this->actingAs($this->admin)->get(route('admin.evaluations.affectations'));
        $response->assertOk();

        $nbQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(30, $nbQueries, "Le chargement de la page d'affectations ne doit pas générer de N+1 (obtenu : {$nbQueries}).");
    }
}
