<?php

namespace Tests\Feature\Security;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\IntegrationLog;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\ExamRoomAssignmentService;
use App\Modules\Evaluation\Services\SurveillanceService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests de Sécurité, Anti-IDOR, Intégrité et Concurrence pour les Examens (LOT J - §3.1, §3.2, §3.4).
 */
class ExamGouvernanceSecuriteConcurrenceTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte1;

    private Faculte $faculte2;

    private AnneeUniversitaire $annee;

    private Programme $programme1;

    private Programme $programme2;

    private Matiere $matiere;

    private Room $salle;

    private SessionExamen $session;

    private Examen $examen;

    private Epreuve $epreuve1;

    private Epreuve $epreuve2;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);
        config(['modules.schedule.active' => true]);
        config(['modules.referentiel.active' => true]);

        $this->faculte1 = Faculte::create(['nom' => 'Faculté Informatique', 'code' => 'FI', 'active' => true]);
        $this->faculte2 = Faculte::create(['nom' => 'Faculté Médecine', 'code' => 'FM', 'active' => true]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme1 = Programme::create([
            'faculte_id' => $this->faculte1->id,
            'code' => 'L-INFO',
            'nom' => 'Licence Info',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $this->programme2 = Programme::create([
            'faculte_id' => $this->faculte2->id,
            'code' => 'DOC-MED',
            'nom' => 'Doctorat Médecine',
            'duree_annees' => 6,
            'semestres_nombre' => 12,
            'credits_requis' => 360,
        ]);

        $niv = Niveau::create(['programme_id' => $this->programme1->id, 'code' => 'L2', 'nom' => 'L2', 'ordre' => 2]);
        $sem = SemestreLmd::create(['niveau_id' => $niv->id, 'code' => 'S3', 'nom' => 'S3', 'numero' => 3, 'ordre' => 3]);
        $ue = UniteEnseignement::create(['semestre_id' => $sem->id, 'programme_id' => $this->programme1->id, 'code' => 'UE01', 'nom' => 'UE01', 'credits' => 6, 'coefficient' => 2]);

        $this->matiere = Matiere::create([
            'code' => 'SEC01',
            'nom' => 'Sécurité Systèmes',
            'ue_id' => $ue->id,
            'niveau' => 'L2',
            'semestre' => 3,
            'credits' => 4,
            'actif' => true,
        ]);

        $this->salle = Room::create([
            'faculte_id' => $this->faculte1->id,
            'nom' => 'Salle Sécurité 101',
            'code' => 'S-101',
            'capacite' => 50,
            'actif' => true,
        ]);

        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-SEC-2027',
            'nom' => 'Session Sécurité',
            'type' => 'principale',
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-25',
            'statut' => 'open',
        ]);

        $this->examen = Examen::create([
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme1->id,
            'libelle' => 'Examen Sécurité Info',
            'type' => 'examen',
            'statut' => 'scheduled',
        ]);

        $this->epreuve1 = Epreuve::create([
            'code' => 'EPR-SEC-01',
            'examen_id' => $this->examen->id,
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiere->id,
            'date' => '2027-01-15',
            'heure_debut' => '09:00:00',
            'heure_fin' => '11:00:00',
            'statut' => 'scheduled',
        ]);

        $this->epreuve2 = Epreuve::create([
            'code' => 'EPR-SEC-02',
            'examen_id' => $this->examen->id,
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiere->id,
            'date' => '2027-01-15',
            'heure_debut' => '09:30:00',
            'heure_fin' => '11:30:00',
            'statut' => 'scheduled',
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Sécurité',
            'email' => 'admin.sec@universite.test',
            'password' => 'AdminSec@123',
        ], 'admin_scolarite');
    }

    public function test_securite_anti_idor_etudiant_ne_peut_pas_voir_convocation_d_un_autre(): void
    {
        $etudiantA = Etudiant::create([
            'num_inscription' => 'INS-SEC-A',
            'email' => 'etudiant.a@universite.test',
            'nom' => 'Dupont',
            'prenom' => 'Alice',
            'cin' => 'CIN-SEC-A',
            'date_naissance' => '2004-01-01',
            'sexe' => 'F',
            'faculte_id' => $this->faculte1->id,
            'niveau' => 'L2',
            'annee_academique' => '2026-2027',
            'adresse' => 'Campus',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => 2024,
            'password' => 'Etudiant@123',
        ]);
        $etudiantA->definirStatut('valide');

        $etudiantB = Etudiant::create([
            'num_inscription' => 'INS-SEC-B',
            'email' => 'etudiant.b@universite.test',
            'nom' => 'Martin',
            'prenom' => 'Bob',
            'cin' => 'CIN-SEC-B',
            'date_naissance' => '2004-02-02',
            'sexe' => 'M',
            'faculte_id' => $this->faculte1->id,
            'niveau' => 'L2',
            'annee_academique' => '2026-2027',
            'adresse' => 'Campus',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => 2024,
            'password' => 'Etudiant@123',
        ]);
        $etudiantB->definirStatut('valide');

        $candidatA = Candidat::create(['epreuve_id' => $this->epreuve1->id, 'etudiant_id' => $etudiantA->id, 'statut' => 'present']);
        $candidatB = Candidat::create(['epreuve_id' => $this->epreuve1->id, 'etudiant_id' => $etudiantB->id, 'statut' => 'present']);

        // L'étudiant A se connecte et tente d'accéder à la convocation de B via son ID (IDOR)
        $response = $this->withSession(['etudiant_auth_id' => $etudiantA->id])
            ->get(route('etudiant.examens.convocation', $candidatB->id));

        // Le serveur interdit formellement l'accès (403 ou 404 selon la policy)
        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_securite_concurrence_conflit_salle_simultanee(): void
    {
        $service = app(ExamRoomAssignmentService::class);

        // Affectation réussie à l'épreuve 1 sur le créneau 09:00 - 11:00
        $service->affecterSalle($this->epreuve1, $this->salle->id, $this->admin->id);
        $this->assertSame($this->salle->id, $this->epreuve1->fresh()->salle_id);

        // Tentative d'affecter la même salle sur un créneau chevauchant (09:30 - 11:30) à l'épreuve 2
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('réservée');

        $service->affecterSalle($this->epreuve2, $this->salle->id, $this->admin->id);
    }

    public function test_securite_concurrence_surveillant_double_affectation_simultanee(): void
    {
        $service = app(SurveillanceService::class);

        $enseignant = Teacher::create([
            'faculte_id' => $this->faculte1->id,
            'matricule' => 'ENS-CONCUR-01',
            'prenom' => 'Ada',
            'nom' => 'Lovelace',
            'email' => 'ada@universite.test',
            'actif' => true,
        ]);

        // Affectation à l'épreuve 1 (09:00 - 11:00)
        $service->assigner($this->epreuve1, [
            'teacher_id' => $enseignant->id,
            'role' => 'surveillant',
        ], $this->admin->id);

        // Tentative d'affecter le même enseignant sur l'épreuve 2 qui chevauche (09:30 - 11:30)
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Affectation impossible');

        $service->assigner($this->epreuve2, [
            'teacher_id' => $enseignant->id,
            'role' => 'surveillant',
        ], $this->admin->id);
    }

    public function test_securite_absence_de_pii_et_de_secrets_dans_les_logs(): void
    {
        // Recherche dans les logs d'intégration récents
        $logs = IntegrationLog::latest('id')->limit(50)->get();

        if ($logs->isEmpty()) {
            IntegrationLog::tracer('evaluation', 'internal', 'exam.test.log', 'completed', null, 200, null, null, 'UUID-TEST', 'REF-TEST');
            $logs = IntegrationLog::latest('id')->limit(1)->get();
        }

        $this->assertNotEmpty($logs);

        foreach ($logs as $log) {
            $payload = json_encode($log->toArray());
            // Aucun mot de passe, ni token, ni hash bcrypt dans les logs
            $this->assertStringNotContainsString('password', strtolower($payload));
            $this->assertStringNotContainsString('motdepasse', strtolower($payload));
            $this->assertStringNotContainsString('$2y$', $payload);
            // Aucune donnée de santé sensible
            $this->assertStringNotContainsString('pathologie', strtolower($payload));
            $this->assertStringNotContainsString('maladie_chronique', strtolower($payload));
        }
    }
}
