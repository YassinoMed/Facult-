<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Evaluation\Services\ExamSurveillanceGroupesOrchestratorService;
use App\Modules\Evaluation\Services\SurveillanceService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite de tests Enterprise pour EA-EXAMENS-05 — Surveillance, Ratios d'Encadrement & Groupes d'Examen (Menus 5 & 8).
 *
 * Valide :
 *  - Permissions d'accès et redirections sécurisées
 *  - Calcul opposable des ratios réglementaires de surveillance (besoinActifs)
 *  - Détection des épreuves sous-surveillées ou sans aucun surveillant
 *  - Détection préventive des conflits horaires d'enseignants
 *  - Remplacement traçable d'un surveillant avec historique scellé
 *  - Audit des cohortes d'examen, détection des surcharges et capacités indéterminées
 *  - Cloisonnement facultaire et garantie zéro écriture sur les routes de consultation
 */
class ExamSurveillanceGroupesEnterpriseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $adminFaculteA;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Matiere $matiere;

    private Room $salle;

    private SessionExamen $session;

    private Examen $examen;

    private Epreuve $epreuve1;

    private Epreuve $epreuve2;

    private Teacher $enseignant1;

    private Teacher $enseignant2;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FST',
            'active' => true,
        ]);

        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Droit',
            'code' => 'FD',
            'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Directeur Examens Central',
            'email' => 'dir.exam.'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->adminFaculteA = User::creerCompteBackOffice([
            'name' => 'Admin Faculté Sciences',
            'email' => 'admin.fst.'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'INFO-L3',
            'nom' => 'Licence 3 Informatique',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $niveau = Niveau::create([
            'programme_id' => $this->programme->id,
            'code' => 'L3',
            'nom' => 'Licence 3',
            'ordre' => 3,
        ]);

        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id,
            'code' => 'S5',
            'nom' => 'Semestre 5',
            'numero' => 5,
            'ordre' => 5,
        ]);

        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id,
            'programme_id' => $this->programme->id,
            'code' => 'UE-INF501',
            'nom' => 'Algorithmique et Systèmes',
            'credits' => 6,
            'coefficient' => 2,
        ]);

        $this->matiere = Matiere::create([
            'code' => 'ALG-501',
            'nom' => 'Algorithmique Avancée',
            'ue_id' => $ue->id,
            'niveau' => 'L3',
            'semestre' => 5,
            'credits' => 6,
            'coefficient' => 2,
        ]);

        $this->salle = Room::create([
            'code' => 'AMPHI-EULER',
            'nom' => 'Amphithéâtre Euler',
            'capacite' => 150,
            'actif' => true,
        ]);

        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-HIVER-27',
            'nom' => 'Session Hiver Janvier 2027',
            'type' => 'principale',
            'date_debut' => '2027-01-15',
            'date_fin' => '2027-01-30',
            'statut' => 'open',
        ]);

        $this->examen = Examen::create([
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examens Semestre 5 Sciences',
            'type' => 'examen',
            'statut' => 'published',
            'responsable_id' => $this->admin->id,
        ]);

        $this->epreuve1 = Epreuve::create([
            'code' => 'EPR-ALG-01',
            'examen_id' => $this->examen->id,
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiere->id,
            'salle_id' => $this->salle->id,
            'date' => '2027-01-20',
            'heure_debut' => '08:30',
            'heure_fin' => '11:30',
            'statut' => 'published',
        ]);

        $this->epreuve2 = Epreuve::create([
            'code' => 'EPR-ALG-02',
            'examen_id' => $this->examen->id,
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiere->id,
            'salle_id' => $this->salle->id,
            'date' => '2027-01-20',
            'heure_debut' => '10:00', // Chevauchement partiel avec epreuve1 (08:30 - 11:30)
            'heure_fin' => '12:30',
            'statut' => 'published',
        ]);

        $this->enseignant1 = Teacher::create([
            'nom' => 'Alami',
            'prenom' => 'Ahmed',
            'email' => 'a.alami.'.uniqid().'@univ.test',
            'matricule' => 'ENS-001',
            'actif' => true,
        ]);

        $this->enseignant2 = Teacher::create([
            'nom' => 'Bennani',
            'prenom' => 'Leila',
            'email' => 'l.bennani.'.uniqid().'@univ.test',
            'matricule' => 'ENS-002',
            'actif' => true,
        ]);
    }

    private function peuplerCandidats(Epreuve $epreuve, int $effectif): void
    {
        for ($i = 1; $i <= $effectif; $i++) {
            $etudiant = Etudiant::create([
                'cin' => 'CD'.random_int(100000, 999999),
                'nom' => 'Etudiant'.$i,
                'prenom' => 'Test'.$i,
                'email' => 'etudiant.'.$i.'.'.uniqid().'@test.ma',
                'date_naissance' => '2004-02-15',
                'sexe' => 'M',
                'faculte_id' => $this->faculteA->id,
                'niveau' => 'L3',
                'annee_academique' => '2026-2027',
                'adresse' => 'Campus',
                'ville' => 'Rabat',
                'code_postal' => '10000',
                'annee_bac' => '2023',
                'password' => 'Pass@12345',
            ]);

            Candidat::create([
                'epreuve_id' => $epreuve->id,
                'etudiant_id' => $etudiant->id,
                'salle_id' => $epreuve->salle_id,
                'numero_anonyme' => sprintf('AN-%04d', $i),
                'statut' => 'present',
            ]);
        }
    }

    public function test_acces_non_authentifie_redirige(): void
    {
        $this->get(route('admin.evaluation.examens.surveillance'))->assertRedirect();
        $this->get(route('admin.evaluation.examens.groupes'))->assertRedirect();
    }

    public function test_audit_surveillance_calcule_exactement_les_besoins_reglementaires(): void
    {
        // 80 candidats -> besoinActifs = max(2, 1, ceil(80/50)) = 2 surveillants
        $this->peuplerCandidats($this->epreuve1, 80);

        $orchestrateur = app(ExamSurveillanceGroupesOrchestratorService::class);
        $audit = $orchestrateur->auditerSurveillance($this->session->id, $this->faculteA->id);

        $this->assertNotEmpty($audit['epreuves_auditees']);
        $epreuveAudit = collect($audit['epreuves_auditees'])->firstWhere('epreuve_id', $this->epreuve1->id);

        $this->assertNotNull($epreuveAudit);
        $this->assertSame(80, $epreuveAudit['candidats_count']);
        $this->assertSame(2, $epreuveAudit['besoin_surveillants']);
        $this->assertSame('sans_surveillant', $epreuveAudit['statut_encadrement']);
        $this->assertSame(2, $epreuveAudit['manque_surveillants']);
    }

    public function test_detection_epreuve_sous_surveillee_et_conforme(): void
    {
        $this->peuplerCandidats($this->epreuve1, 60); // Besoin = 2 surveillants

        // Affectation d'un seul surveillant -> sous-surveillée
        Surveillance::create([
            'epreuve_id' => $this->epreuve1->id,
            'teacher_id' => $this->enseignant1->id,
            'role' => 'principal',
            'statut' => 'assigne',
        ]);

        $orchestrateur = app(ExamSurveillanceGroupesOrchestratorService::class);
        $audit = $orchestrateur->auditerSurveillance($this->session->id, $this->faculteA->id);

        $epreuveAudit = collect($audit['epreuves_auditees'])->firstWhere('epreuve_id', $this->epreuve1->id);
        $this->assertSame('sous_surveillee', $epreuveAudit['statut_encadrement']);
        $this->assertSame(1, $epreuveAudit['surveillants_affectes']);
        $this->assertSame(1, $epreuveAudit['manque_surveillants']);

        // Affectation du deuxième surveillant -> conforme
        Surveillance::create([
            'epreuve_id' => $this->epreuve1->id,
            'teacher_id' => $this->enseignant2->id,
            'role' => 'adjoint',
            'statut' => 'confirme',
        ]);

        $audit2 = $orchestrateur->auditerSurveillance($this->session->id, $this->faculteA->id);
        $epreuveAudit2 = collect($audit2['epreuves_auditees'])->firstWhere('epreuve_id', $this->epreuve1->id);
        $this->assertSame('conforme', $epreuveAudit2['statut_encadrement']);
        $this->assertSame(2, $epreuveAudit2['surveillants_affectes']);
        $this->assertSame(0, $epreuveAudit2['manque_surveillants']);
    }

    public function test_detection_conflit_horaire_surveillant_sur_deux_epreuves(): void
    {
        // Affecter l'enseignant 1 sur l'épreuve 1 (08:30 - 11:30)
        Surveillance::create([
            'epreuve_id' => $this->epreuve1->id,
            'teacher_id' => $this->enseignant1->id,
            'role' => 'principal',
            'statut' => 'assigne',
        ]);

        $orchestrateur = app(ExamSurveillanceGroupesOrchestratorService::class);

        // L'épreuve 2 a lieu le même jour de 10:00 à 12:30 -> Conflit avec épreuve 1
        $conflits = $orchestrateur->detecterConflitsEnseignant($this->epreuve2, $this->enseignant1->id);

        $this->assertNotEmpty($conflits);
        $this->assertStringContainsString("Conflit d'horaire", $conflits[0]);
    }

    public function test_remplacement_surveillant_archive_le_titulaire_et_conserve_historique(): void
    {
        $surveillanceTitulaire = Surveillance::create([
            'epreuve_id' => $this->epreuve1->id,
            'teacher_id' => $this->enseignant1->id,
            'role' => 'principal',
            'statut' => 'assigne',
        ]);

        $service = app(SurveillanceService::class);
        $remplacant = $service->remplacer(
            $surveillanceTitulaire,
            ['teacher_id' => $this->enseignant2->id, 'role' => 'principal'],
            'Raison médicale urgente',
            $this->admin->id
        );

        $surveillanceTitulaire->refresh();

        $this->assertSame('remplace', $surveillanceTitulaire->statut);
        $this->assertSame('Raison médicale urgente', $surveillanceTitulaire->motif_absence);
        $this->assertSame($this->enseignant2->id, $remplacant->teacher_id);
        $this->assertSame($surveillanceTitulaire->id, $remplacant->remplace_surveillance_id);
    }

    public function test_audit_groupes_detecte_surcharges_et_groupes_sans_capacite(): void
    {
        // 1. Groupe conforme : capacité 50, 30 étudiants
        $groupe1 = Group::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'GRP-INF-01',
            'nom' => 'Groupe TD 1 Informatique',
            'niveau' => 'L3',
            'capacite' => 50,
            'actif' => true,
        ]);

        // 2. Groupe en sur-effectif : capacité 30, 45 étudiants
        $groupe2 = Group::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'GRP-INF-02',
            'nom' => 'Groupe TD 2 Informatique',
            'niveau' => 'L3',
            'capacite' => 30,
            'actif' => true,
        ]);

        // 3. Groupe sans capacité définie
        $groupe3 = Group::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'GRP-INF-03',
            'nom' => 'Groupe TD 3 Informatique',
            'niveau' => 'L3',
            'capacite' => null,
            'actif' => true,
        ]);

        // Attacher des étudiants au groupe 2
        for ($i = 0; $i < 45; $i++) {
            $etudiant = Etudiant::create([
                'cin' => 'EF'.random_int(100000, 999999),
                'nom' => 'Nom'.$i,
                'prenom' => 'Prenom'.$i,
                'email' => 'etud.grp.'.$i.'.'.uniqid().'@univ.test',
                'date_naissance' => '2004-01-01',
                'sexe' => 'F',
                'faculte_id' => $this->faculteA->id,
                'groupe_id' => $groupe2->id,
                'niveau' => 'L3',
                'annee_academique' => '2026-2027',
                'adresse' => 'Rabat',
                'ville' => 'Rabat',
                'code_postal' => '10000',
                'annee_bac' => '2023',
                'password' => 'Pass@12345',
            ]);
        }

        $orchestrateur = app(ExamSurveillanceGroupesOrchestratorService::class);
        $audit = $orchestrateur->auditerGroupes($this->faculteA->id);

        $this->assertSame(3, $audit['total_groupes']);
        $this->assertSame(45, $audit['effectif_total']);
        $this->assertSame(80, $audit['capacite_totale']); // 50 + 30
        $this->assertSame(1, $audit['groupes_sans_capacite']);
        $this->assertSame(1, $audit['groupes_surcharges']);
    }

    public function test_cloisonnement_facultaire_et_zero_ecriture_en_consultation(): void
    {
        $epreuveB = Epreuve::create([
            'code' => 'EPR-DROIT-01',
            'examen_id' => $this->examen->id,
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiere->id,
            'date' => '2027-01-22',
            'heure_debut' => '09:00',
            'heure_fin' => '12:00',
            'statut' => 'published',
        ]);

        $groupeB = Group::create([
            'faculte_id' => $this->faculteB->id,
            'code' => 'DROIT-TD-01',
            'nom' => 'TD Droit Civil',
            'niveau' => 'L1',
            'capacite' => 40,
            'actif' => true,
        ]);

        // Surveillance sur épreuve de la faculté B
        $survB = Surveillance::create([
            'epreuve_id' => $epreuveB->id,
            'teacher_id' => $this->enseignant1->id,
            'role' => 'principal',
            'statut' => 'assigne',
        ]);

        // Admin restreint à Faculté A consulte les groupes et surveillance
        $reponseGroupes = $this->actingAs($this->adminFaculteA)->get(route('admin.evaluation.examens.groupes'));
        $reponseGroupes->assertOk();
        $reponseGroupes->assertDontSee('DROIT-TD-01');

        $nbSurvAvant = Surveillance::count();
        $nbGroupesAvant = Group::count();

        $this->actingAs($this->admin)->get(route('admin.evaluation.examens.surveillance'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.evaluation.examens.groupes'))->assertOk();

        // Garantie zéro écriture sur les requêtes GET
        $this->assertSame($nbSurvAvant, Surveillance::count());
        $this->assertSame($nbGroupesAvant, Group::count());
    }

    public function test_vue_surveillance_affiche_kpi_et_table_sans_erreur(): void
    {
        Surveillance::create([
            'epreuve_id' => $this->epreuve1->id,
            'teacher_id' => $this->enseignant1->id,
            'role' => 'principal',
            'statut' => 'assigne',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.evaluation.examens.surveillance'))
            ->assertOk()
            ->assertSee('Surveillance')
            ->assertSee('Gardes actives')
            ->assertSee('Couverture')
            ->assertSee('Ahmed Alami')
            ->assertSee('Algorithmique Avancée');
    }

    public function test_vue_groupes_affiche_modes_et_kpi_sans_erreur(): void
    {
        Group::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'GRP-INF-L3',
            'nom' => 'Groupe L3 Info',
            'niveau' => 'L3',
            'capacite' => 45,
            'actif' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.evaluation.examens.groupes'))
            ->assertOk()
            ->assertSee('Groupes d\'examen')
            ->assertSee('Groupes répertoriés')
            ->assertSee('Mode A Actif')
            ->assertSee('Mode B Optionnel')
            ->assertSee('GRP-INF-L3');
    }

    public function test_vue_groupes_mode_b_amphitheatres_et_decision_d04(): void
    {
        // 1. Création d'un amphithéâtre étalonné
        $amphi = Room::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'AMPHI-GAUSS',
            'nom' => 'Amphithéâtre Carl Friedrich Gauss',
            'capacite' => 200,
            'type_salle' => 'amphitheatre',
            'actif' => true,
        ]);

        // 2. Création de groupes TD sources pour alimenter la cohorte d'amphi
        $grpTD1 = Group::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'TD-INFO-01',
            'nom' => 'TD Info 1',
            'niveau' => 'L2',
            'capacite' => 40,
            'actif' => true,
        ]);
        $grpTD2 = Group::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'TD-INFO-02',
            'nom' => 'TD Info 2',
            'niveau' => 'L2',
            'capacite' => 40,
            'actif' => true,
        ]);

        // 3. Appel de la route avec mode=B
        $reponse = $this->actingAs($this->admin)
            ->get(route('admin.evaluation.examens.groupes', ['mode' => 'B']));

        $reponse->assertOk()
            ->assertSee('Mode B Optionnel')
            ->assertSee('Amphithéâtres')
            ->assertSee('Regroupement spécifique aux examens (Décision D-04)')
            ->assertSee('Règle de capacité opposable (Décision D-04')
            ->assertSee('Capacité Utile (D-04)')
            ->assertSee('AMPHI-GAUSS')
            ->assertSee('Amphithéâtre Carl Friedrich Gauss')
            ->assertSee('200') // Capacité brute
            ->assertSee('100 places opposables') // 200 * 0.5 = 100 (Décision D-04)
            ->assertSee('1 place / 2 (50%)')
            ->assertSee('Conforme D-04')
            ->assertSee('COH-D04-AMPHI-GAUSS-01'); // Cohorte générée
    }
}
