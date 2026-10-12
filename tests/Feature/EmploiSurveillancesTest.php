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
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Suite de tests fonctionnels, de sécurité et de performance pour
 * l'Emploi du temps des surveillances.
 */
class EmploiSurveillancesTest extends TestCase
{
    use RefreshDatabase;

    private User $adminScolarite;

    private User $adminFaculteA;

    private User $adminFaculteB;

    private User $utilisateurSansDroit;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Programme $programmeA;

    private Programme $programmeB;

    private UniteEnseignement $ueA;

    private Matiere $matiereA;

    private Group $groupeA;

    private Room $salle1;

    private Room $salle2;

    private Teacher $enseignant1;

    private Teacher $enseignant2;

    private Teacher $enseignant3;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculteA = Faculte::create(['nom' => 'Faculté des Sciences', 'code' => 'FS', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'Faculté de Droit', 'code' => 'FD', 'active' => true]);

        $this->adminScolarite = User::factory()->create([
            'email' => 'admin.scolarite@univ.test',
            'role' => 'admin_scolarite',
            'faculte_id' => null,
            'actif' => true,
        ]);

        $this->adminFaculteA = User::factory()->create([
            'email' => 'admin.fsa@univ.test',
            'role' => 'admin_faculte',
            'faculte_id' => $this->faculteA->id,
            'actif' => true,
        ]);

        $this->adminFaculteB = User::factory()->create([
            'email' => 'admin.fdb@univ.test',
            'role' => 'admin_faculte',
            'faculte_id' => $this->faculteB->id,
            'actif' => true,
        ]);

        $this->utilisateurSansDroit = User::factory()->create([
            'email' => 'visiteur@univ.test',
            'role' => 'etudiant',
            'faculte_id' => null,
            'actif' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programmeA = Programme::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'L-INFO',
            'nom' => 'Licence Informatique',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $this->programmeB = Programme::create([
            'faculte_id' => $this->faculteB->id,
            'code' => 'L-DROIT',
            'nom' => 'Licence Droit',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $niveauA = Niveau::create(['programme_id' => $this->programmeA->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $semestreA = SemestreLmd::create(['niveau_id' => $niveauA->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);

        $this->ueA = UniteEnseignement::create([
            'semestre_id' => $semestreA->id,
            'programme_id' => $this->programmeA->id,
            'code' => 'UE-ALGO',
            'nom' => 'Algorithmique',
            'credits' => 6,
            'coefficient' => 2,
        ]);

        $this->matiereA = Matiere::create([
            'code' => 'INFO101',
            'nom' => 'Introduction aux Algorithmes',
            'ue_id' => $this->ueA->id,
            'niveau' => 'L1',
            'semestre' => 1,
            'credits' => 6,
            'coefficient' => 2,
            'actif' => true,
        ]);

        $this->groupeA = Group::create([
            'code' => 'INFO-G1',
            'nom' => 'Groupe 1 INFO',
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $this->programmeA->id,
            'niveau' => 'L1',
            'actif' => true,
        ]);

        $this->salle1 = Room::create(['code' => 'AMPHI-A', 'nom' => 'Amphi A', 'capacite' => 100, 'faculte_id' => $this->faculteA->id, 'actif' => true]);
        $this->salle2 = Room::create(['code' => 'SALLE-101', 'nom' => 'Salle TD 101', 'capacite' => 40, 'faculte_id' => $this->faculteA->id, 'actif' => true]);

        $this->enseignant1 = Teacher::create([
            'matricule' => 'ENS-001',
            'nom' => 'Ben Ali',
            'prenom' => 'Ahmed',
            'email' => 'ahmed.benali@univ.test',
            'actif' => true,
        ]);

        $this->enseignant2 = Teacher::create([
            'matricule' => 'ENS-002',
            'nom' => 'Yassine',
            'prenom' => 'Mohamed',
            'email' => 'mohamed.yassine@univ.test',
            'actif' => true,
        ]);

        $this->enseignant3 = Teacher::create([
            'matricule' => 'ENS-003',
            'nom' => 'Salah',
            'prenom' => 'Sami',
            'email' => 'sami.salah@univ.test',
            'actif' => true,
        ]);
    }

    private function creerSession(string $nom = 'Session Principale S1', string $type = 'principale'): SessionExamen
    {
        return SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-'.strtoupper($type).'-'.random_int(1000, 9999),
            'nom' => $nom,
            'type' => $type,
            'date_debut' => '2027-01-05',
            'date_fin' => '2027-01-20',
            'statut' => 'draft',
        ]);
    }

    private function creerEpreuve(
        SessionExamen $session,
        ?Programme $programme = null,
        ?Matiere $matiere = null,
        ?Group $groupe = null,
        ?Room $salle = null,
        string $date = '2027-01-06',
        string $heureDebut = '08:00',
        string $heureFin = '10:00'
    ): Epreuve {
        $prog = $programme ?? $this->programmeA;
        $mat = $matiere ?? $this->matiereA;
        $grp = $groupe ?? $this->groupeA;
        $sal = $salle ?? $this->salle1;

        $examen = Examen::create([
            'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $prog->id,
            'groupe_id' => $grp->id,
            'libelle' => 'Examen '.$mat->code,
            'type' => 'examen',
            'statut' => 'scheduled',
            'responsable_id' => $this->adminScolarite->id,
        ]);

        return Epreuve::create([
            'examen_id' => $examen->id,
            'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $mat->id,
            'ue_id' => $mat->ue_id,
            'groupe_id' => $grp->id,
            'salle_id' => $sal->id,
            'date' => $date,
            'heure_debut' => $heureDebut,
            'heure_fin' => $heureFin,
            'duree_minutes' => 120,
            'type_evaluation' => 'examen',
            'code' => 'EP-'.random_int(1000, 9999),
            'statut' => 'scheduled',
            'capacite_necessaire' => 30,
        ]);
    }

    /**
     * 1. Sécurité & Contrôle d'accès : permission epreuves.view exigée.
     */
    public function test_acces_refuse_sans_permission(): void
    {
        $this->actingAs($this->utilisateurSansDroit)
            ->get(route('admin.evaluations.surveillances.emploi'))
            ->assertForbidden();
    }

    /**
     * 2. Affichage vue globale : session, épreuve, salle, enseignants visibles.
     */
    public function test_affichage_vue_globale_complete(): void
    {
        $session = $this->creerSession('Session Hiver 2027');
        $epreuve = $this->creerEpreuve($session, date: '2027-01-08', heureDebut: '08:00', heureFin: '10:00');

        Surveillance::create([
            'epreuve_id' => $epreuve->id,
            'teacher_id' => $this->enseignant1->id,
            'role' => 'principal',
            'statut' => 'assigne',
        ]);

        Surveillance::create([
            'epreuve_id' => $epreuve->id,
            'teacher_id' => $this->enseignant2->id,
            'role' => 'adjoint',
            'statut' => 'assigne',
        ]);

        Surveillance::create([
            'epreuve_id' => $epreuve->id,
            'teacher_id' => $this->enseignant3->id,
            'role' => 'reserve',
            'statut' => 'assigne',
        ]);

        $response = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluations.surveillances.emploi', ['session_id' => $session->id]));

        $response->assertOk();
        $response->assertSee('Session Hiver 2027');
        $response->assertSee('INFO101');
        $response->assertSee('Introduction aux Algorithmes');
        $response->assertSee('AMPHI-A');
        $response->assertSee('Ahmed Ben Ali');
        $response->assertSee('Mohamed Yassine');
        $response->assertSee('Sami Salah');
        $response->assertSee('Principal');
        $response->assertSee('Adjoint');
        $response->assertSee('Réserve');
        $response->assertSee('Couverture des surveillances');
        $response->assertSee('Équilibrage des enseignants');
    }

    /**
     * 3. Support de l'alias vue=globale.
     */
    public function test_support_alias_vue_globale(): void
    {
        $session = $this->creerSession();
        $this->creerEpreuve($session);

        $response = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluations.surveillances.emploi', ['vue' => 'globale', 'session_id' => $session->id]));

        $response->assertOk();
        $response->assertViewHas('vue', 'global');
    }

    /**
     * 4. Vue par enseignant : statistiques individuelles et créneaux.
     */
    public function test_vue_par_enseignant_avec_kpis_et_3_jours(): void
    {
        $session = $this->creerSession();

        // Créer 4 épreuves sur 4 jours différents pour déclencher l'alerte > 3 jours
        $dates = ['2027-01-11', '2027-01-12', '2027-01-13', '2027-01-14'];
        foreach ($dates as $date) {
            $epreuve = $this->creerEpreuve($session, date: $date);
            Surveillance::create([
                'epreuve_id' => $epreuve->id,
                'teacher_id' => $this->enseignant1->id,
                'role' => 'principal',
                'statut' => 'assigne',
            ]);
        }

        $response = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluations.surveillances.emploi', [
                'vue' => 'enseignant',
                'teacher_id' => $this->enseignant1->id,
            ]));

        $response->assertOk();
        $response->assertSee('Ahmed Ben Ali');
        $response->assertSee('Dispersion : 4 jours');
        $response->assertSee('Nombre de surveillances');
        $response->assertSee('4'); // 4 gardes
    }

    /**
     * 5. Vue par examen : détails de l'épreuve et de la couverture.
     */
    public function test_vue_par_examen(): void
    {
        $session = $this->creerSession();
        $epreuve = $this->creerEpreuve($session);

        Surveillance::create([
            'epreuve_id' => $epreuve->id,
            'teacher_id' => $this->enseignant1->id,
            'role' => 'principal',
            'statut' => 'assigne',
        ]);

        $response = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluations.surveillances.emploi', [
                'vue' => 'examen',
                'epreuve_id' => $epreuve->id,
            ]));

        $response->assertOk();
        $response->assertSee("Inspection d'épreuve", false);
        $response->assertSee('Introduction aux Algorithmes');
        $response->assertSee('AMPHI-A');
        $response->assertSee('Ahmed Ben Ali');
        $response->assertSee('Surveillants requis');
    }

    /**
     * 6. Filtres : session, enseignant, salle, statut.
     */
    public function test_filtres_multi_criteres(): void
    {
        $session1 = $this->creerSession('Session 1');
        $session2 = $this->creerSession('Session 2');

        $epreuve1 = $this->creerEpreuve($session1, salle: $this->salle1);
        $epreuve2 = $this->creerEpreuve($session2, salle: $this->salle2);

        Surveillance::create([
            'epreuve_id' => $epreuve1->id,
            'teacher_id' => $this->enseignant1->id,
            'role' => 'principal',
            'statut' => 'assigne',
        ]);

        // Filtre par session
        $resSession = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluations.surveillances.emploi', ['session_id' => $session1->id]));
        $resSession->assertOk();
        $resSession->assertSee($epreuve1->code);
        $resSession->assertDontSee($epreuve2->code);

        // Filtre par salle
        $resSalle = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluations.surveillances.emploi', ['salle_id' => $this->salle2->id]));
        $resSalle->assertOk();
        $resSalle->assertSee($epreuve2->code);
        $resSalle->assertDontSee($epreuve1->code);

        // Filtre par enseignant
        $resEns = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluations.surveillances.emploi', ['teacher_id' => $this->enseignant1->id]));
        $resEns->assertOk();
        $resEns->assertSee($epreuve1->code);
        $resEns->assertDontSee($epreuve2->code);
    }

    /**
     * 7. Sécurité & Isolation faculté : Anti-IDOR sur les épreuves d'autres facultés.
     */
    public function test_isolation_faculte_et_anti_idor(): void
    {
        $session = $this->creerSession();

        $niveauB = Niveau::create(['programme_id' => $this->programmeB->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $semestreB = SemestreLmd::create(['niveau_id' => $niveauB->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);
        $ueB = UniteEnseignement::create([
            'semestre_id' => $semestreB->id,
            'programme_id' => $this->programmeB->id,
            'code' => 'UE-DROIT',
            'nom' => 'Droit Civil',
            'credits' => 6,
            'coefficient' => 2,
        ]);
        $matiereB = Matiere::create([
            'code' => 'DROIT101',
            'nom' => 'Droit Civil 1',
            'ue_id' => $ueB->id,
            'niveau' => 'L1',
            'semestre' => 1,
            'credits' => 6,
            'coefficient' => 2,
            'actif' => true,
        ]);
        $groupeB = Group::create([
            'code' => 'DROIT-G1',
            'nom' => 'Groupe Droit 1',
            'faculte_id' => $this->faculteB->id,
            'programme_id' => $this->programmeB->id,
            'niveau' => 'L1',
            'actif' => true,
        ]);
        $salleB = Room::create(['code' => 'AMPHI-DROIT', 'nom' => 'Amphi Droit', 'capacite' => 80, 'faculte_id' => $this->faculteB->id, 'actif' => true]);

        $epreuveA = $this->creerEpreuve($session);
        $epreuveB = $this->creerEpreuve($session, programme: $this->programmeB, matiere: $matiereB, groupe: $groupeB, salle: $salleB);

        // Admin Faculté A ne doit voir QUE épreuve A
        $response = $this->actingAs($this->adminFaculteA)
            ->get(route('admin.evaluations.surveillances.emploi'));

        $response->assertOk();
        $response->assertSee($epreuveA->code);
        $response->assertDontSee($epreuveB->code);

        // Tentative IDOR directe sur la vue examen pour l'épreuve de Faculté B -> 404
        $this->actingAs($this->adminFaculteA)
            ->get(route('admin.evaluations.surveillances.emploi', ['vue' => 'examen', 'epreuve_id' => $epreuveB->id]))
            ->assertNotFound();
    }

    /**
     * 8. Conflit : Deux examens simultanés pour le même enseignant.
     */
    public function test_detection_conflit_deux_examens_simultanes(): void
    {
        $session = $this->creerSession();
        $date = '2027-01-15';
        $heureDebut = '08:00';
        $heureFin = '10:00';

        $epreuve1 = $this->creerEpreuve($session, date: $date, heureDebut: $heureDebut, heureFin: $heureFin);
        $epreuve2 = $this->creerEpreuve($session, date: $date, heureDebut: $heureDebut, heureFin: $heureFin);

        Surveillance::create([
            'epreuve_id' => $epreuve1->id,
            'teacher_id' => $this->enseignant1->id,
            'role' => 'principal',
            'statut' => 'assigne',
        ]);

        Surveillance::create([
            'epreuve_id' => $epreuve2->id,
            'teacher_id' => $this->enseignant1->id,
            'role' => 'adjoint',
            'statut' => 'assigne',
        ]);

        $response = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluations.surveillances.emploi', ['session_id' => $session->id]));

        $response->assertOk();
        $response->assertSee('Ahmed Ben Ali est déjà affecté sur');
    }

    /**
     * 9. Conflit : Conflit avec cours pédagogique existant (ScheduleSlot).
     */
    public function test_detection_conflit_cours_pedagogique(): void
    {
        $session = $this->creerSession();
        // Vendredi 2027-01-15 correspond à "friday"
        $date = '2027-01-15';
        $heureDebut = '08:00';
        $heureFin = '10:00';

        $schedule = Schedule::create([
            'nom' => 'Emploi du temps INFO-G1',
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $this->programmeA->id,
            'groupe_id' => $this->groupeA->id,
            'semestre' => 1,
            'statut' => 'published',
        ]);

        ScheduleSlot::create([
            'schedule_id' => $schedule->id,
            'matiere_id' => $this->matiereA->id,
            'teacher_id' => $this->enseignant1->id,
            'room_id' => $this->salle1->id,
            'day_of_week' => 'friday',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'type' => 'course',
            'status' => 'scheduled',
        ]);

        $epreuve = $this->creerEpreuve($session, date: $date, heureDebut: $heureDebut, heureFin: $heureFin);

        Surveillance::create([
            'epreuve_id' => $epreuve->id,
            'teacher_id' => $this->enseignant1->id,
            'role' => 'principal',
            'statut' => 'assigne',
        ]);

        $response = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluations.surveillances.emploi', ['session_id' => $session->id]));

        $response->assertOk();
        $response->assertSee('Ahmed Ben Ali a déjà un cours');
    }

    /**
     * 10. Conflit : Enseignant inactif affecté.
     */
    public function test_detection_conflit_enseignant_inactif(): void
    {
        $session = $this->creerSession();
        $this->enseignant2->update(['actif' => false]);

        $epreuve = $this->creerEpreuve($session);

        Surveillance::create([
            'epreuve_id' => $epreuve->id,
            'teacher_id' => $this->enseignant2->id,
            'role' => 'adjoint',
            'statut' => 'assigne',
        ]);

        $response = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluations.surveillances.emploi', ['session_id' => $session->id]));

        $response->assertOk();
        $response->assertSee('Mohamed Yassine est inactif');
    }

    /**
     * 11. Conflit : Capacité salle insuffisante.
     */
    public function test_detection_conflit_capacite_salle_insuffisante(): void
    {
        $session = $this->creerSession();
        $sallePetite = Room::create(['code' => 'MINI-ROOM', 'nom' => 'Mini', 'capacite' => 10, 'actif' => true]);

        $epreuve = $this->creerEpreuve($session, salle: $sallePetite);
        $epreuve->update(['capacite_necessaire' => 50]);

        $response = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluations.surveillances.emploi', ['session_id' => $session->id]));

        $response->assertOk();
        $response->assertSee('Capacité insuffisante : 50 étudiants pour 10 places');
    }

    /**
     * 12. Statistiques d'équilibrage et de couverture : conformité totale avec la DB.
     */
    public function test_statistiques_et_couverture_exactes_depuis_db(): void
    {
        $session = $this->creerSession();
        $epreuve = $this->creerEpreuve($session);

        // 2 surveillants actifs (poids 1.0 chacun) + 1 réserve (poids 0.5)
        Surveillance::create([
            'epreuve_id' => $epreuve->id,
            'teacher_id' => $this->enseignant1->id,
            'role' => 'principal',
            'statut' => 'assigne',
        ]);
        Surveillance::create([
            'epreuve_id' => $epreuve->id,
            'teacher_id' => $this->enseignant2->id,
            'role' => 'adjoint',
            'statut' => 'assigne',
        ]);
        Surveillance::create([
            'epreuve_id' => $epreuve->id,
            'teacher_id' => $this->enseignant3->id,
            'role' => 'reserve',
            'statut' => 'assigne',
        ]);

        $response = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluations.surveillances.emploi', ['session_id' => $session->id]));

        $response->assertOk();
        $stats = $response->viewData('stats');
        $couverture = $response->viewData('couverture');

        $this->assertEquals(3, $stats['enseignants_mobilises']);
        $this->assertEquals(3, $stats['surveillances']);
        $this->assertEquals(1, $stats['gardes_reserve']);
        $this->assertEquals(1, $couverture['completes']);
        $this->assertEquals(100.0, $couverture['taux']);
    }

    /**
     * 13. Performance : Absence de N+1 (nombre de requêtes constant quel que soit le nombre d'épreuves).
     */
    public function test_absence_de_requetes_n_plus_1(): void
    {
        $session = $this->creerSession();

        // Créer 2 épreuves
        for ($i = 0; $i < 2; $i++) {
            $ep = $this->creerEpreuve($session, date: '2027-01-0'.$i);
            Surveillance::create([
                'epreuve_id' => $ep->id,
                'teacher_id' => $this->enseignant1->id,
                'role' => 'principal',
                'statut' => 'assigne',
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluations.surveillances.emploi', ['session_id' => $session->id]));
        $nbRequetesBase = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Ajouter 8 épreuves supplémentaires (total 10 épreuves)
        for ($i = 2; $i < 10; $i++) {
            $ep = $this->creerEpreuve($session, date: '2027-01-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
            Surveillance::create([
                'epreuve_id' => $ep->id,
                'teacher_id' => $this->enseignant1->id,
                'role' => 'principal',
                'statut' => 'assigne',
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluations.surveillances.emploi', ['session_id' => $session->id]));
        $nbRequetesMulti = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Le nombre de requêtes ne doit pas exploser avec 5x plus d'épreuves
        // (pas de requêtes en boucle par épreuve ni par créneau)
        $this->assertLessThanOrEqual(
            $nbRequetesBase + 3,
            $nbRequetesMulti,
            "Requêtes excessives détectées : base={$nbRequetesBase}, multi={$nbRequetesMulti} (risque N+1)"
        );
    }

    /**
     * 14. Export PDF : génération correcte du document A4 paysage.
     */
    public function test_export_pdf_emploi_surveillances(): void
    {
        $session = $this->creerSession();
        $epreuve = $this->creerEpreuve($session);

        Surveillance::create([
            'epreuve_id' => $epreuve->id,
            'teacher_id' => $this->enseignant1->id,
            'role' => 'principal',
            'statut' => 'assigne',
        ]);

        $response = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluations.surveillances.emploi.pdf', ['session_id' => $session->id]));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    /**
     * 15. Export CSV : par jour et par enseignant.
     */
    public function test_export_csv_emploi_surveillances(): void
    {
        $session = $this->creerSession();
        $epreuve = $this->creerEpreuve($session);

        Surveillance::create([
            'epreuve_id' => $epreuve->id,
            'teacher_id' => $this->enseignant1->id,
            'role' => 'principal',
            'statut' => 'assigne',
        ]);

        // Export par jour (mode par défaut)
        $resCsvJour = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluations.surveillances.emploi.csv', ['session_id' => $session->id, 'mode' => 'jour']));

        $resCsvJour->assertOk();
        $this->assertStringContainsString('text/csv', (string) $resCsvJour->headers->get('Content-Type'));
        $contenuJour = $resCsvJour->streamedContent();
        $this->assertStringContainsString('Date;Horaire;Examen;Matière;Niveau;Groupe;Salle;Enseignant;Rôle;Statut;Conflits', $contenuJour);
        $this->assertStringContainsString('Ahmed Ben Ali', $contenuJour);
        $this->assertStringContainsString('Principal', $contenuJour);

        // Export par enseignant
        $resCsvEns = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluations.surveillances.emploi.csv', ['session_id' => $session->id, 'mode' => 'enseignant']));

        $resCsvEns->assertOk();
        $contenuEns = $resCsvEns->streamedContent();
        $this->assertStringContainsString('Enseignant;Date;Horaire;Examen;Salle;Rôle', $contenuEns);
        $this->assertStringContainsString('Ahmed Ben Ali', $contenuEns);
    }
}
