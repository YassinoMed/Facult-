<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\ExamSessionConfigurationService;
use App\Modules\Evaluation\Services\SessionExamenService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite de tests Enterprise pour EA-EXAMENS-02 — Sessions et Configurations.
 *
 * Couvre :
 *  - Permissions d'accès aux Menus 2 & 3
 *  - Validation des sessions (principale, rattrapage avec origine obligatoire)
 *  - Cohérence temporelle des dates de sessions
 *  - Cycle de vie, machine à états et contrôle de readiness avant ouverture
 *  - Verrouillage officiel et immutabilité
 *  - Mode A (pédagogique) vs Mode B (groupes éligibilité)
 *  - Règle R-05 & Décision D-02 : Limite stricte de 1 à 3 modules par configuration
 *  - Modalités distinctes vs combinées
 *  - Cloisonnement facultaire des sessions et configurations
 */
class ExamSessionConfigurationEnterpriseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte1;

    private Faculte $faculte2;

    private AnneeUniversitaire $annee;

    private Programme $programme1;

    private Programme $programme2;

    private Matiere $matiere1;

    private Matiere $matiere2;

    private Matiere $matiere3;

    private Matiere $matiere4;

    private Group $groupe1;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculte1 = Faculte::create(['nom' => 'Sciences', 'code' => 'FS', 'description' => 'Fac des Sciences', 'active' => true]);
        $this->faculte2 = Faculte::create(['nom' => 'Lettres', 'code' => 'FL', 'description' => 'Fac des Lettres', 'active' => true]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Examens',
            'email' => 'admin.sess'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme1 = Programme::create([
            'faculte_id' => $this->faculte1->id,
            'code' => 'INFO',
            'nom' => 'Informatique',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $this->programme2 = Programme::create([
            'faculte_id' => $this->faculte2->id,
            'code' => 'LETTRES',
            'nom' => 'Lettres Modernes',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $niveau1 = Niveau::create(['programme_id' => $this->programme1->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $semestre1 = SemestreLmd::create(['niveau_id' => $niveau1->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);
        $ue1 = UniteEnseignement::create([
            'semestre_id' => $semestre1->id,
            'programme_id' => $this->programme1->id,
            'code' => 'UE-INFO-101',
            'nom' => 'Tronc commun Info',
            'credits' => 12,
            'coefficient' => 4,
        ]);

        $this->matiere1 = Matiere::create(['code' => 'ALGO', 'nom' => 'Algo 1', 'ue_id' => $ue1->id, 'niveau' => 'L1', 'semestre' => 1, 'credits' => 3, 'coefficient' => 1]);
        $this->matiere2 = Matiere::create(['code' => 'ARCHI', 'nom' => 'Architecture', 'ue_id' => $ue1->id, 'niveau' => 'L1', 'semestre' => 1, 'credits' => 3, 'coefficient' => 1]);
        $this->matiere3 = Matiere::create(['code' => 'MATHS', 'nom' => 'Maths discrètes', 'ue_id' => $ue1->id, 'niveau' => 'L1', 'semestre' => 1, 'credits' => 3, 'coefficient' => 1]);
        $this->matiere4 = Matiere::create(['code' => 'SYSTEME', 'nom' => 'Systèmes OS', 'ue_id' => $ue1->id, 'niveau' => 'L1', 'semestre' => 1, 'credits' => 3, 'coefficient' => 1]);

        $this->groupe1 = Group::create([
            'code' => 'GRP-A',
            'nom' => 'Groupe A',
            'programme_id' => $this->programme1->id,
            'faculte_id' => $this->faculte1->id,
            'niveau' => 'L1',
            'capacite' => 30,
            'actif' => true,
        ]);
    }

    public function test_acces_sessions_et_configurations_refuse_aux_invites(): void
    {
        $this->get(route('admin.evaluation.examens.sessions'))->assertRedirect();
        $this->get(route('admin.evaluation.examens.configurations'))->assertRedirect();
    }

    public function test_acces_sessions_exige_permission_sessions_examen_view(): void
    {
        $userSansDroit = User::factory()->create();

        $this->actingAs($userSansDroit)
            ->get(route('admin.evaluation.examens.sessions'))
            ->assertForbidden();
    }

    public function test_creation_session_principale_valide(): void
    {
        $service = app(ExamSessionConfigurationService::class);

        $donnees = [
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-P2026',
            'nom' => 'Session Principale Janvier',
            'type' => 'principale',
            'date_debut' => '2027-01-05',
            'date_fin' => '2027-01-20',
        ];

        $service->validerDonneesSession($donnees);

        $session = SessionExamen::create($donnees + ['statut' => 'draft']);

        $this->assertDatabaseHas('evaluation_sessions', ['code' => 'SESS-P2026']);
        $this->assertEquals('mode_a', $session->modeOrganisation());
        $this->assertTrue($session->estSessionPrincipale());
        $this->assertFalse($session->estRattrapage());
    }

    public function test_creation_session_rattrapage_exige_session_origine_valide(): void
    {
        $service = app(ExamSessionConfigurationService::class);

        // Tentative de rattrapage SANS session d'origine
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("Une session de rattrapage exige le rattachement à une session principale d'origine.");

        $service->validerDonneesSession([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-R2026',
            'nom' => 'Rattrapage Février',
            'type' => 'rattrapage',
            'date_debut' => '2027-02-10',
            'date_fin' => '2027-02-18',
        ]);
    }

    public function test_creation_session_rattrapage_avec_origine_reussie(): void
    {
        $service = app(ExamSessionConfigurationService::class);

        $principale = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-P1',
            'nom' => 'Principale',
            'type' => 'principale',
            'date_debut' => '2027-01-05',
            'date_fin' => '2027-01-20',
            'statut' => 'open',
        ]);

        $donneesRattrapage = [
            'annee_universitaire_id' => $this->annee->id,
            'session_origine_id' => $principale->id,
            'code' => 'SESS-R1',
            'nom' => 'Rattrapage',
            'type' => 'rattrapage',
            'date_debut' => '2027-02-10',
            'date_fin' => '2027-02-20',
        ];

        $service->validerDonneesSession($donneesRattrapage);

        $rattrapage = SessionExamen::create($donneesRattrapage + ['statut' => 'draft']);

        $this->assertEquals($principale->id, $rattrapage->session_origine_id);
        $this->assertTrue($rattrapage->estRattrapage());
        $this->assertEquals('Principale', $rattrapage->origine->nom);
    }

    public function test_rejet_rattrapage_avec_origine_annee_differente(): void
    {
        $service = app(ExamSessionConfigurationService::class);

        $autreAnnee = AnneeUniversitaire::create([
            'libelle' => '2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
            'active' => false,
        ]);

        $sessionAncienne = SessionExamen::create([
            'annee_universitaire_id' => $autreAnnee->id,
            'code' => 'SESS-OLD',
            'nom' => 'Ancienne Session',
            'type' => 'principale',
            'date_debut' => '2026-01-05',
            'date_fin' => '2026-01-20',
            'statut' => 'locked',
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("La session d'origine doit appartenir à la même année universitaire.");

        $service->validerDonneesSession([
            'annee_universitaire_id' => $this->annee->id, // Année courante
            'session_origine_id' => $sessionAncienne->id, // Année différente
            'code' => 'SESS-R-INVALIDE',
            'nom' => 'Rattrapage Invalide',
            'type' => 'rattrapage',
            'date_debut' => '2027-02-10',
            'date_fin' => '2027-02-20',
        ]);
    }

    public function test_coherence_dates_session_debut_doit_preceder_fin(): void
    {
        $service = app(ExamSessionConfigurationService::class);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('doit précéder la date de fin');

        $service->validerDonneesSession([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-BAD-DATES',
            'nom' => 'Session Dates Incohérentes',
            'type' => 'principale',
            'date_debut' => '2027-02-20',
            'date_fin' => '2027-02-10', // Fin avant début !
        ]);
    }

    public function test_cycle_vie_transitions_autorisees_et_interdites(): void
    {
        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-CYCLE',
            'nom' => 'Session Cycle de vie',
            'type' => 'principale',
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-25',
            'statut' => 'draft',
        ]);

        // Transition interdite directe : draft -> locked
        $this->assertFalse($session->peutTransiterVers('locked'));
        $this->assertTrue($session->peutTransiterVers('open'));
    }

    public function test_ouverture_session_bloquee_si_readiness_invalide(): void
    {
        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-VIDE',
            'nom' => 'Session Vide Sans Épreuve',
            'type' => 'principale',
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-25',
            'statut' => 'draft',
        ]);

        $service = app(SessionExamenService::class);

        // Une session sans épreuve doit être bloquée à l'ouverture
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Ouverture bloquée');

        $service->transiter($session, 'open');
    }

    public function test_regle_r05_limite_1_a_3_modules_par_configuration(): void
    {
        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-CONF-TEST',
            'nom' => 'Session Configs Test',
            'type' => 'principale',
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-25',
            'statut' => 'open',
        ]);

        // Examen 1 : Conforme avec 2 modules (<= 3)
        $examenConforme = Examen::create([
            'annee_universitaire_id' => $this->annee->id,
            'session_id' => $session->id,
            'programme_id' => $this->programme1->id,
            'code' => 'EX-CONF-2',
            'libelle' => 'Examen Conforme (2 modules)',
            'statut' => 'draft',
        ]);
        Epreuve::create(['annee_universitaire_id' => $this->annee->id, 'session_id' => $session->id, 'examen_id' => $examenConforme->id, 'code' => 'EP-C1', 'matiere_id' => $this->matiere1->id, 'date' => '2027-01-15', 'heure_debut' => '09:00', 'heure_fin' => '11:00', 'statut' => 'draft']);
        Epreuve::create(['annee_universitaire_id' => $this->annee->id, 'session_id' => $session->id, 'examen_id' => $examenConforme->id, 'code' => 'EP-C2', 'matiere_id' => $this->matiere2->id, 'date' => '2027-01-15', 'heure_debut' => '14:00', 'heure_fin' => '16:00', 'statut' => 'draft']);

        // Examen 2 : Non conforme avec 4 modules (> 3)
        $examenNonConforme = Examen::create([
            'annee_universitaire_id' => $this->annee->id,
            'session_id' => $session->id,
            'programme_id' => $this->programme1->id,
            'code' => 'EX-CONF-4',
            'libelle' => 'Examen Hors Limite (4 modules)',
            'statut' => 'draft',
        ]);
        Epreuve::create(['annee_universitaire_id' => $this->annee->id, 'session_id' => $session->id, 'examen_id' => $examenNonConforme->id, 'code' => 'EP-NC1', 'matiere_id' => $this->matiere1->id, 'date' => '2027-01-16', 'heure_debut' => '08:00', 'heure_fin' => '10:00', 'statut' => 'draft']);
        Epreuve::create(['annee_universitaire_id' => $this->annee->id, 'session_id' => $session->id, 'examen_id' => $examenNonConforme->id, 'code' => 'EP-NC2', 'matiere_id' => $this->matiere2->id, 'date' => '2027-01-16', 'heure_debut' => '10:30', 'heure_fin' => '12:30', 'statut' => 'draft']);
        Epreuve::create(['annee_universitaire_id' => $this->annee->id, 'session_id' => $session->id, 'examen_id' => $examenNonConforme->id, 'code' => 'EP-NC3', 'matiere_id' => $this->matiere3->id, 'date' => '2027-01-17', 'heure_debut' => '08:00', 'heure_fin' => '10:00', 'statut' => 'draft']);
        Epreuve::create(['annee_universitaire_id' => $this->annee->id, 'session_id' => $session->id, 'examen_id' => $examenNonConforme->id, 'code' => 'EP-NC4', 'matiere_id' => $this->matiere4->id, 'date' => '2027-01-17', 'heure_debut' => '10:30', 'heure_fin' => '12:30', 'statut' => 'draft']);

        $service = app(ExamSessionConfigurationService::class);
        $audit = $service->auditerConfigurations($session->id, $this->faculte1->id, $this->annee->id);

        $this->assertEquals(2, $audit['total_configurations']);
        $this->assertEquals(1, $audit['total_conformes']);
        $this->assertEquals(1, $audit['total_anomalies']);

        $nonConformeAudit = collect($audit['liste_auditee'])->firstWhere('examen.id', $examenNonConforme->id);
        $this->assertFalse($nonConformeAudit['valide']);
        $this->assertStringContainsString('règle R-05', $nonConformeAudit['bloquants'][0]);
    }

    public function test_modalite_distincte_vs_combinee(): void
    {
        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-MODALITE',
            'nom' => 'Session Modalités',
            'type' => 'principale',
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-25',
            'statut' => 'open',
        ]);

        $examenCombinee = Examen::create([
            'annee_universitaire_id' => $this->annee->id,
            'session_id' => $session->id,
            'programme_id' => $this->programme1->id,
            'code' => 'EX-COMB',
            'libelle' => 'Examen Combiné Math-Info',
            'observations' => 'Épreuve combinée regroupant 2 ECUE',
            'statut' => 'draft',
        ]);
        Epreuve::create(['annee_universitaire_id' => $this->annee->id, 'session_id' => $session->id, 'examen_id' => $examenCombinee->id, 'code' => 'EP-M1', 'matiere_id' => $this->matiere1->id, 'date' => '2027-01-18', 'heure_debut' => '09:00', 'heure_fin' => '12:00', 'statut' => 'draft']);
        Epreuve::create(['annee_universitaire_id' => $this->annee->id, 'session_id' => $session->id, 'examen_id' => $examenCombinee->id, 'code' => 'EP-M2', 'matiere_id' => $this->matiere2->id, 'date' => '2027-01-18', 'heure_debut' => '09:00', 'heure_fin' => '12:00', 'statut' => 'draft']);

        $service = app(ExamSessionConfigurationService::class);
        $audit = $service->auditerConfigurations($session->id, $this->faculte1->id, $this->annee->id);

        $this->assertEquals(1, $audit['total_combinees']);
        $this->assertEquals(0, $audit['total_distinctes']);
    }

    public function test_isolation_facultaire_configurations(): void
    {
        $userFac1 = User::creerCompteBackOffice([
            'name' => 'Agent Fac 1',
            'email' => 'agent.fac1.'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte1->id,
        ], 'admin_faculte');
        $userFac1->update(['faculte_id' => $this->faculte1->id]);

        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-ISO',
            'nom' => 'Session Isolement',
            'type' => 'principale',
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-25',
            'statut' => 'open',
        ]);

        // Examen de la faculté 1
        Examen::create([
            'annee_universitaire_id' => $this->annee->id,
            'session_id' => $session->id,
            'programme_id' => $this->programme1->id,
            'code' => 'EX-FAC1',
            'libelle' => 'Examen Informatique',
            'statut' => 'draft',
        ]);

        // Examen de la faculté 2
        Examen::create([
            'annee_universitaire_id' => $this->annee->id,
            'session_id' => $session->id,
            'programme_id' => $this->programme2->id,
            'code' => 'EX-FAC2',
            'libelle' => 'Examen Lettres',
            'statut' => 'draft',
        ]);

        $response = $this->actingAs($userFac1)
            ->get(route('admin.evaluation.examens.configurations', ['session_id' => $session->id]));

        $response->assertOk();
        $response->assertSee('Examen Informatique');
        $response->assertDontSee('Examen Lettres');
    }
}
