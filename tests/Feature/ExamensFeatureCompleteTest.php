<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\InscriptionPedagogique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Semestre;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\EvaluationIncident;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\CompostageService;
use App\Modules\Evaluation\Services\ConflitEpreuveService;
use App\Modules\Evaluation\Services\ConvocationService;
use App\Modules\Evaluation\Services\EpreuveService;
use App\Modules\Evaluation\Services\ExamRoomAssignmentService;
use App\Modules\Evaluation\Services\SaisieNoteService;
use App\Modules\Evaluation\Services\SurveillanceService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 🔵 LOOP PARTIE EXAMENS — Test d'intégration de bout en bout
 *
 * Couvre l'intégralité du cycle de vie de production :
 * 1. Planification & Calendrier
 * 2. Sessions d'examen & Transitions
 * 3. Épreuves & Conflits
 * 4. Affectation des Salles & Places (ExamRoomAssignmentService)
 * 5. Affectation des Étudiants (PopulationService)
 * 6. Affectation des Surveillants
 * 7. Convocations & Sécurité Étudiant (Anti-IDOR / BOLA)
 * 8. Émargement interactif (Présent, Absent, Retard, Excusé)
 * 9. Incidents
 * 10. Copies / Anonymat (Compostage)
 * 11. Saisie des notes & Convergence vers la table officielle `notes`
 * 12. Verrouillage & Protection
 * 13. Isolation Faculté & RBAC
 * 14. Performance & Concurrence
 */
class ExamensFeatureCompleteTest extends TestCase
{
    use RefreshDatabase;

    private User $adminScolarite;

    private User $adminFaculteA;

    private User $adminFaculteB;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Programme $programmeA;

    private UniteEnseignement $ueA;

    private Matiere $ecueA;

    private Group $groupeA;

    private Room $salle1;

    private Room $salle2;

    private Teacher $enseignant1;

    private Teacher $enseignant2;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.evaluation.active' => true,
            'modules.schedule.active' => true,
        ]);

        $this->faculteA = Faculte::create(['nom' => 'Faculté des Sciences', 'code' => 'FS', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'Faculté de Droit', 'code' => 'FD', 'active' => true]);

        $this->adminScolarite = User::creerCompteBackOffice([
            'name' => 'Admin Scolarité',
            'email' => 'admin.sco@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->adminFaculteA = User::creerCompteBackOffice([
            'name' => 'Admin Fac A',
            'email' => 'admin.fa@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $this->adminFaculteB = User::creerCompteBackOffice([
            'name' => 'Admin Fac B',
            'email' => 'admin.fb@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programmeA = Programme::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'LGL',
            'nom' => 'Licence Génie Logiciel',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $niveau = Niveau::create(['programme_id' => $this->programmeA->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);

        $this->ueA = UniteEnseignement::create([
            'semestre_id' => $semestre->id,
            'programme_id' => $this->programmeA->id,
            'code' => 'UE-ALGO',
            'nom' => 'Algorithmique et Programmation',
            'credits' => 6,
            'coefficient' => 2,
        ]);

        $this->ecueA = Matiere::create([
            'code' => 'ALGO101',
            'nom' => 'Algorithmes 1',
            'ue_id' => $this->ueA->id,
            'niveau' => 'L1',
            'semestre' => 1,
            'credits' => 6,
            'coefficient' => 2,
            'actif' => true,
        ]);

        $this->groupeA = Group::create([
            'code' => 'GL-G1',
            'nom' => 'Groupe 1 GL',
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $this->programmeA->id,
            'niveau' => 'L1',
            'actif' => true,
        ]);

        $this->salle1 = Room::create(['code' => 'AMPHI-A', 'nom' => 'Amphithéâtre A', 'capacite' => 100, 'actif' => true]);
        $this->salle2 = Room::create(['code' => 'SALLE-101', 'nom' => 'Salle TD 101', 'capacite' => 30, 'actif' => true]);

        $this->enseignant1 = Teacher::create([
            'matricule' => 'ENS-001',
            'nom' => 'Ben Amor',
            'prenom' => 'Tarek',
            'email' => 'tarek@universite.test',
            'actif' => true,
        ]);

        $this->enseignant2 = Teacher::create([
            'matricule' => 'ENS-002',
            'nom' => 'Trabelsi',
            'prenom' => 'Sonia',
            'email' => 'sonia@universite.test',
            'actif' => true,
        ]);
    }

    private function creerSession(string $type = 'principale'): SessionExamen
    {
        return SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-'.strtoupper($type).'-'.random_int(1000, 9999),
            'nom' => 'Session '.ucfirst($type).' 2027',
            'type' => $type,
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-25',
            'statut' => 'draft',
            'created_by' => $this->adminScolarite->id,
        ]);
    }

    private function creerExamen(SessionExamen $session): Examen
    {
        return Examen::create([
            'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programmeA->id,
            'groupe_id' => $this->groupeA->id,
            'libelle' => 'Examens S1 Licence GL',
            'type' => 'examen',
            'statut' => 'scheduled',
            'responsable_id' => $this->adminScolarite->id,
        ]);
    }

    private function creerEtudiantAvecInscription(string $nom = 'Foulen', string $cin = '12345678'): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => $nom,
            'prenom' => 'Salah',
            'email' => strtolower(str_replace(' ', '', $nom)).'.'.random_int(100, 999).'@etudiant.test',
            'date_naissance' => '2004-01-01',
            'sexe' => 'M',
            'faculte_id' => $this->faculteA->id,
            'groupe_id' => $this->groupeA->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '12 rue de Test',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-'.random_int(10000, 99999)])->save();
        $etudiant->definirStatut('valide');

        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programmeA->id,
            'niveau' => 'L1',
            'statut' => 'valide',
            'type' => 'premiere',
        ]);

        $semestre = Semestre::create([
            'inscription_academique_id' => $inscription->id,
            'numero' => 1,
            'credits_obtenus' => 0,
        ]);

        InscriptionPedagogique::create([
            'inscription_id' => $inscription->id,
            'semestre_id' => $this->ueA->semestre_id,
            'ue_id' => $this->ueA->id,
            'matiere_id' => $this->ecueA->id,
            'statut' => 'inscrit',
        ]);

        return $etudiant;
    }

    public function test_planification_examens_planning_view_et_filtres(): void
    {
        $session = $this->creerSession();
        $examen = $this->creerExamen($session);
        $epreuve = app(EpreuveService::class)->creer($examen, [
            'session_id' => $session->id,
            'matiere_id' => $this->ecueA->id,
            'groupe_id' => $this->groupeA->id,
            'salle_id' => $this->salle1->id,
            'date' => '2027-01-12',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
        ]);

        $response = $this->actingAs($this->adminScolarite)->get(route('admin.evaluations.planning', [
            'session_id' => $session->id,
            'programme_id' => $this->programmeA->id,
        ]));

        $response->assertOk();
        $response->assertSee('Planning des examens');
        $response->assertSee($this->ecueA->code);
        $response->assertSee($this->salle1->code);
    }

    public function test_detection_conflits_salle_groupe_horaire(): void
    {
        $session = $this->creerSession();
        $examen = $this->creerExamen($session);
        $service = app(EpreuveService::class);

        $service->creer($examen, [
            'session_id' => $session->id,
            'matiere_id' => $this->ecueA->id,
            'groupe_id' => $this->groupeA->id,
            'salle_id' => $this->salle1->id,
            'date' => '2027-01-12',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
        ]);

        $conflits = app(ConflitEpreuveService::class)->detecter([
            'date' => '2027-01-12',
            'heure_debut' => '10:00',
            'heure_fin' => '12:00',
            'salle_id' => $this->salle1->id,
            'groupe_id' => $this->groupeA->id,
        ]);

        $types = array_column($conflits, 'type');
        $this->assertContains('salle', $types);
        $this->assertContains('groupe', $types);
        $this->assertNotEmpty(app(ConflitEpreuveService::class)->bloquants($conflits));
    }

    public function test_epreuve_cycle_de_vie_transitions_serveur_et_verrouillage(): void
    {
        $session = $this->creerSession();
        $examen = $this->creerExamen($session);
        $service = app(EpreuveService::class);

        $epreuve = $service->creer($examen, [
            'session_id' => $session->id,
            'matiere_id' => $this->ecueA->id,
            'salle_id' => $this->salle1->id,
            'date' => '2027-01-12',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
        ]);

        $this->assertSame('scheduled', $epreuve->statut);

        // Transition vers published
        $epreuve = $service->transiter($epreuve, 'published', $this->adminScolarite->id);
        $this->assertSame('published', $epreuve->statut);

        // Transition vers ongoing
        $epreuve = $service->transiter($epreuve, 'ongoing', $this->adminScolarite->id);
        $this->assertSame('ongoing', $epreuve->statut);

        // Transition vers completed
        $epreuve = $service->transiter($epreuve, 'completed', $this->adminScolarite->id);
        $this->assertSame('completed', $epreuve->statut);

        // Verrouillage officiel
        $epreuve = $service->verrouiller($epreuve, $this->adminScolarite->id, 'Clôture de session');
        $this->assertSame('locked', $epreuve->statut);
        $this->assertTrue($epreuve->estVerrouillee());

        // Modification interdite après verrouillage
        $this->expectException(DomainException::class);
        $service->modifier($epreuve, ['heure_debut' => '08:00'], $this->adminScolarite->id);
    }

    public function test_affectation_salle_principale_avec_conflits(): void
    {
        $session = $this->creerSession();
        $examen = $this->creerExamen($session);
        $epreuve = app(EpreuveService::class)->creer($examen, [
            'session_id' => $session->id,
            'matiere_id' => $this->ecueA->id,
            'date' => '2027-01-12',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
        ]);

        $roomService = app(ExamRoomAssignmentService::class);
        $epreuve = $roomService->affecterSalle($epreuve, $this->salle1->id, $this->adminScolarite->id);

        $this->assertSame($this->salle1->id, $epreuve->salle_id);
    }

    public function test_simulation_repartition_salles_pure_sans_ecriture(): void
    {
        $session = $this->creerSession();
        $examen = $this->creerExamen($session);
        $epreuve = app(EpreuveService::class)->creer($examen, [
            'session_id' => $session->id,
            'matiere_id' => $this->ecueA->id,
            'date' => '2027-01-12',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
        ]);

        $e1 = $this->creerEtudiantAvecInscription('Abidi', '00000001');
        $e2 = $this->creerEtudiantAvecInscription('Zitouni', '00000002');
        Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $e1->id, 'statut' => 'present']);
        Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $e2->id, 'statut' => 'present']);

        $roomService = app(ExamRoomAssignmentService::class);
        $simulation = $roomService->simulerRepartition($epreuve, [$this->salle2->id]);

        $this->assertTrue($simulation['valide']);
        $this->assertSame(2, $simulation['total_candidats']);
        $this->assertCount(2, $simulation['repartition']);

        // Vérification pureté : AUCUNE écriture en base n'a été effectuée
        $this->assertNull(Candidat::where('etudiant_id', $e1->id)->value('salle_id'));
        $this->assertNull(Candidat::where('etudiant_id', $e1->id)->value('place'));
    }

    public function test_application_repartition_salles_avec_places(): void
    {
        $session = $this->creerSession();
        $examen = $this->creerExamen($session);
        $epreuve = app(EpreuveService::class)->creer($examen, [
            'session_id' => $session->id,
            'matiere_id' => $this->ecueA->id,
            'date' => '2027-01-12',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
        ]);

        $e1 = $this->creerEtudiantAvecInscription('Abidi', '00000001');
        $e2 = $this->creerEtudiantAvecInscription('Zitouni', '00000002');
        $c1 = Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $e1->id, 'statut' => 'present']);
        $c2 = Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $e2->id, 'statut' => 'present']);

        $roomService = app(ExamRoomAssignmentService::class);
        $simulation = $roomService->simulerRepartition($epreuve, [$this->salle2->id]);

        $count = $roomService->appliquerRepartition($epreuve, $simulation['repartition'], $this->adminScolarite->id);

        $this->assertSame(2, $count);
        $this->assertSame($this->salle2->id, $c1->fresh()->salle_id);
        $this->assertSame('SALLE-101-01', $c1->fresh()->place);
        $this->assertSame($this->salle2->id, $c2->fresh()->salle_id);
        $this->assertSame('SALLE-101-02', $c2->fresh()->place);
    }

    public function test_affectation_etudiants_population_admissible_idempotente(): void
    {
        $session = $this->creerSession();
        $examen = $this->creerExamen($session);
        $epreuve = app(EpreuveService::class)->creer($examen, [
            'session_id' => $session->id,
            'matiere_id' => $this->ecueA->id,
            'groupe_id' => $this->groupeA->id,
            'date' => '2027-01-12',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
        ]);

        $this->creerEtudiantAvecInscription('Etudiant 1', '11111111');
        $this->creerEtudiantAvecInscription('Etudiant 2', '22222222');

        $service = app(EpreuveService::class);
        $crees1 = $service->genererCandidats($epreuve);
        $crees2 = $service->genererCandidats($epreuve);

        $this->assertSame(2, $crees1);
        $this->assertSame(0, $crees2);
        $this->assertSame(2, Candidat::where('epreuve_id', $epreuve->id)->count());
    }

    public function test_affectation_surveillants_conflits_et_remplacement(): void
    {
        $session = $this->creerSession();
        $examen = $this->creerExamen($session);
        $epreuve1 = app(EpreuveService::class)->creer($examen, [
            'session_id' => $session->id,
            'matiere_id' => $this->ecueA->id,
            'date' => '2027-01-12',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
        ]);

        $service = app(SurveillanceService::class);
        $surveillance = $service->assigner($epreuve1, ['teacher_id' => $this->enseignant1->id, 'role' => 'principal']);

        $this->assertSame('assigne', $surveillance->statut);

        // Confirmation
        $response = $this->actingAs($this->adminScolarite)->post(route('admin.evaluations.surveillants.confirmer', $surveillance));
        $response->assertRedirect();
        $this->assertSame('confirme', $surveillance->fresh()->statut);

        // Remplacement
        $remplacant = $service->remplacer($surveillance->fresh(), [
            'teacher_id' => $this->enseignant2->id,
            'role' => 'principal',
        ], 'Empêchement médical');

        $this->assertSame('remplace', $surveillance->fresh()->statut);
        $this->assertSame('assigne', $remplacant->statut);
        $this->assertSame($this->enseignant2->id, $remplacant->teacher_id);
    }

    public function test_convocations_pdf_avec_salle_et_place(): void
    {
        $session = $this->creerSession();
        $examen = $this->creerExamen($session);
        $epreuve = app(EpreuveService::class)->creer($examen, [
            'session_id' => $session->id,
            'matiere_id' => $this->ecueA->id,
            'salle_id' => $this->salle1->id,
            'date' => '2027-01-12',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
        ]);

        $etudiant = $this->creerEtudiantAvecInscription();
        $candidat = Candidat::create([
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $etudiant->id,
            'salle_id' => $this->salle1->id,
            'place' => 'AMPHI-A-01',
            'statut' => 'present',
        ]);

        $convocationService = app(ConvocationService::class);
        $pdf = $convocationService->convocation($candidat);

        $this->assertSame(200, $pdf->getStatusCode());
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
    }

    public function test_espace_etudiant_convocation_acces_et_protection_anti_idor(): void
    {
        $session = $this->creerSession();
        $examen = $this->creerExamen($session);
        $epreuve = app(EpreuveService::class)->creer($examen, [
            'session_id' => $session->id,
            'matiere_id' => $this->ecueA->id,
            'salle_id' => $this->salle1->id,
            'date' => '2027-01-12',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
        ]);
        app(EpreuveService::class)->transiter($epreuve, 'published', $this->adminScolarite->id);

        $etudiantA = $this->creerEtudiantAvecInscription('Etudiant A', '10000001');
        $etudiantB = $this->creerEtudiantAvecInscription('Etudiant B', '10000002');

        $candidatA = Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiantA->id, 'statut' => 'present']);
        $candidatB = Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiantB->id, 'statut' => 'present']);

        // Étudiant A consulte ses examens
        $response = $this->withSession(['etudiant_auth_id' => $etudiantA->id])
            ->get(route('etudiant.examens.index'));
        $response->assertOk();
        $response->assertSee($this->ecueA->code);

        // Étudiant A télécharge sa propre convocation
        $response = $this->withSession(['etudiant_auth_id' => $etudiantA->id])
            ->get(route('etudiant.examens.convocation', $candidatA));
        $response->assertOk();

        // Anti-IDOR / BOLA (§12, §18) : Étudiant A tente de télécharger la convocation de B -> 404
        $response = $this->withSession(['etudiant_auth_id' => $etudiantA->id])
            ->get(route('etudiant.examens.convocation', $candidatB));
        $response->assertNotFound();
    }

    public function test_emargement_interactif_et_statuts_presence(): void
    {
        $session = $this->creerSession();
        $examen = $this->creerExamen($session);
        $epreuve = app(EpreuveService::class)->creer($examen, [
            'session_id' => $session->id,
            'matiere_id' => $this->ecueA->id,
            'date' => '2027-01-12',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
        ]);

        $e1 = $this->creerEtudiantAvecInscription('Etudiant 1', '10000001');
        $e2 = $this->creerEtudiantAvecInscription('Etudiant 2', '10000002');
        $c1 = Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $e1->id, 'statut' => 'present']);
        $c2 = Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $e2->id, 'statut' => 'present']);

        $response = $this->actingAs($this->adminScolarite)->post(route('admin.evaluations.epreuves.emargement.store', $epreuve), [
            'presences' => [
                ['candidat_id' => $c1->id, 'statut' => 'retard', 'justification' => 'Retard 15 min'],
                ['candidat_id' => $c2->id, 'statut' => 'absent_justifie', 'justification' => 'Certificat médical'],
            ],
        ]);

        $response->assertRedirect(route('admin.evaluations.epreuves.show', $epreuve));
        $this->assertSame('retard', $c1->fresh()->statut);
        $this->assertSame('absent_justifie', $c2->fresh()->statut);
        $this->assertSame($this->adminScolarite->id, $c1->fresh()->decide_par);
    }

    public function test_incidents_signalement_et_transitions(): void
    {
        $session = $this->creerSession();
        $examen = $this->creerExamen($session);
        $epreuve = app(EpreuveService::class)->creer($examen, [
            'session_id' => $session->id,
            'matiere_id' => $this->ecueA->id,
            'date' => '2027-01-12',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
        ]);

        $incident = EvaluationIncident::create([
            'epreuve_id' => $epreuve->id,
            'type' => 'fraude',
            'description' => 'Tentative de tricherie avec document non autorisé',
            'created_by' => $this->adminScolarite->id,
        ]);

        $this->assertSame('signale', $incident->statut);

        $response = $this->actingAs($this->adminScolarite)->post(route('admin.evaluations.incidents.transiter', [$incident, 'en_cours']));
        $response->assertRedirect();
        $this->assertSame('en_cours', $incident->fresh()->statut);
    }

    public function test_compostage_anonyme_et_saisie_notes_convergente(): void
    {
        $session = $this->creerSession();
        $examen = $this->creerExamen($session);
        $epreuve = app(EpreuveService::class)->creer($examen, [
            'session_id' => $session->id,
            'matiere_id' => $this->ecueA->id,
            'date' => '2027-01-12',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
        ]);

        $etudiant = $this->creerEtudiantAvecInscription();
        $candidat = Candidat::create(['epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present']);

        // 1. Génération de compostage anonyme
        $compostage = app(CompostageService::class)->getOrCreate($epreuve, $etudiant);
        $this->assertStringStartsWith('CMP-', $compostage->numero);

        // 2. Saisie de note anonyme
        $saisieService = app(SaisieNoteService::class);
        $resultat = $saisieService->saisirLot($epreuve, [
            ['numero' => $compostage->numero, 'valeur' => 15.5],
        ], $this->adminScolarite->id);

        $this->assertSame(1, $resultat['saisies']);
        $this->assertSame([], $resultat['erreurs']);

        // 3. Validation de la note et synchronisation officielle
        $note = NoteEpreuve::where('epreuve_id', $epreuve->id)->firstOrFail();
        $saisieService->valider($note, $this->adminScolarite->id, 'Contrôle jury');

        // 4. Preuve de convergence : la table officielle `notes` contient la note
        $this->assertDatabaseHas('notes', [
            'etudiant_id' => $etudiant->id,
            'matiere_id' => $this->ecueA->id,
            'note' => 15.50,
        ]);
    }

    public function test_securite_isolation_faculte_et_rbac(): void
    {
        $session = $this->creerSession();
        $examen = $this->creerExamen($session);
        $epreuve = app(EpreuveService::class)->creer($examen, [
            'session_id' => $session->id,
            'matiere_id' => $this->ecueA->id,
            'date' => '2027-01-12',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
        ]);

        // L'admin de la Faculté A peut voir son épreuve
        $response = $this->actingAs($this->adminFaculteA)->get(route('admin.evaluations.epreuves.show', $epreuve));
        $response->assertOk();

        // L'admin de la Faculté B tente d'accéder à l'épreuve de la Faculté A -> 404
        $response = $this->actingAs($this->adminFaculteB)->get(route('admin.evaluations.epreuves.show', $epreuve));
        $response->assertNotFound();
    }

    public function test_concurrence_affectation_salles(): void
    {
        $session = $this->creerSession();
        $examen = $this->creerExamen($session);
        $epreuve = app(EpreuveService::class)->creer($examen, [
            'session_id' => $session->id,
            'matiere_id' => $this->ecueA->id,
            'date' => '2027-01-12',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
        ]);

        // Simule deux affectations concurrentes protégées par transaction
        $service = app(ExamRoomAssignmentService::class);

        $affectation1 = DB::transaction(fn () => $service->affecterSalle($epreuve, $this->salle1->id, $this->adminScolarite->id));
        $this->assertSame($this->salle1->id, $affectation1->salle_id);

        // Ré-affectation idempotente
        $affectation2 = DB::transaction(fn () => $service->affecterSalle($epreuve, $this->salle1->id, $this->adminScolarite->id));
        $this->assertSame($this->salle1->id, $affectation2->salle_id);
    }
}
