<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\InscriptionPedagogique;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Evaluation\Services\ConvocationService;
use App\Modules\Evaluation\Services\CorrespondanceExamenService;
use App\Modules\Evaluation\Services\EpreuveService;
use App\Modules\Evaluation\Services\ExamDocumentsProfessionnelsService;
use App\Modules\Evaluation\Services\ExamGroupeVirtuelService;
use App\Modules\Evaluation\Services\ExamPublicationOrchestratorService;
use App\Modules\Evaluation\Services\ExamRoomAssignmentService;
use App\Modules\Evaluation\Services\GroupeVirtuelExamenService;
use App\Modules\Evaluation\Services\PreparationExamenService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Services\ExamScheduleEngineService;
use App\Modules\StudentDossiers\Contracts\PdfGeneratorInterface;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP GLOBAL — Correspondance stricte Matière ↔ Groupes ↔ Étudiants ↔ Examens.
 *
 * Couvre les 14 exigences minimales de la Section 6 (TESTS) ainsi que la
 * visibilité UX (Section 4) et les contrôles de sécurité serveur (Section 5).
 */
class CorrespondanceMatiereGroupesEtudiantsExamensTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Programme $programmeInfo;

    private Programme $programmeGestion;

    private Niveau $niveauL2;

    private Niveau $niveauL3;

    private SemestreLmd $semestreS3;

    private SemestreLmd $semestreS5;

    private UniteEnseignement $ueInfoL2;

    private UniteEnseignement $ueInfoL3;

    private Matiere $matiereAlgoL2;

    private Matiere $matiereBddL2;

    private Matiere $matiereIaL3;

    private Group $groupeL2A;

    private Group $groupeL2B;

    private Group $groupeL3A;

    private Group $groupeFaculteB;

    private Room $salleAmphi;

    private Teacher $surveillant1;

    private Teacher $surveillant2;

    private SessionExamen $session;

    private Examen $examenL2;

    private Epreuve $epreuveAlgoG1;

    private User $adminScolarite;

    private User $adminFaculteB;

    private User $utilisateurSansDroit;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.evaluation.active' => true,
            'modules.schedule.active' => true,
            'modules.referentiel.active' => true,
            'modules.inscription.active' => true,
        ]);

        // Capturer le HTML généré pour inspecter les listes d'émargement et convocations PDF
        $this->app->bind(PdfGeneratorInterface::class, fn () => new class implements PdfGeneratorInterface
        {
            public function generer(string $html, array $options = []): string
            {
                return "%PDF-1.4\n".$html;
            }
        });

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences et Technologies',
            'code' => 'FST',
            'active' => true,
        ]);

        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Droit et Gestion',
            'code' => 'FDG',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'est_active' => true,
            'active' => true,
        ]);

        $this->programmeInfo = Programme::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'LIC-INFO',
            'nom' => 'Licence Informatique',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
            'statut' => 'actif',
        ]);

        $this->programmeGestion = Programme::create([
            'faculte_id' => $this->faculteB->id,
            'code' => 'LIC-GEST',
            'nom' => 'Licence Gestion',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
            'statut' => 'actif',
        ]);

        $this->niveauL2 = Niveau::create([
            'programme_id' => $this->programmeInfo->id,
            'code' => 'L2',
            'nom' => 'Licence 2',
            'ordre' => 2,
        ]);

        $this->niveauL3 = Niveau::create([
            'programme_id' => $this->programmeInfo->id,
            'code' => 'L3',
            'nom' => 'Licence 3',
            'ordre' => 3,
        ]);

        $this->semestreS3 = SemestreLmd::create([
            'niveau_id' => $this->niveauL2->id,
            'code' => 'S3',
            'nom' => 'Semestre 3',
            'numero' => 3,
            'ordre' => 3,
        ]);

        $this->semestreS5 = SemestreLmd::create([
            'niveau_id' => $this->niveauL3->id,
            'code' => 'S5',
            'nom' => 'Semestre 5',
            'numero' => 5,
            'ordre' => 5,
        ]);

        $this->ueInfoL2 = UniteEnseignement::create([
            'semestre_id' => $this->semestreS3->id,
            'programme_id' => $this->programmeInfo->id,
            'code' => 'UE-INFO-S3',
            'nom' => 'UE Fondamentale L2',
            'credits' => 6,
            'coefficient' => 2,
        ]);

        $this->ueInfoL3 = UniteEnseignement::create([
            'semestre_id' => $this->semestreS5->id,
            'programme_id' => $this->programmeInfo->id,
            'code' => 'UE-INFO-S5',
            'nom' => 'UE Spécialité L3',
            'credits' => 6,
            'coefficient' => 2,
        ]);

        $this->matiereAlgoL2 = Matiere::create([
            'ue_id' => $this->ueInfoL2->id,
            'code' => 'MAT-ALGO-L2',
            'nom' => 'Algorithmique et Structures de Données',
            'filiere' => 'Informatique',
            'niveau' => 'L2',
            'semestre' => 3,
            'credits' => 4,
            'coefficient' => 2,
            'actif' => true,
        ]);

        $this->matiereBddL2 = Matiere::create([
            'ue_id' => $this->ueInfoL2->id,
            'code' => 'MAT-BDD-L2',
            'nom' => 'Bases de Données Relationnelles',
            'filiere' => 'Informatique',
            'niveau' => 'L2',
            'semestre' => 3,
            'credits' => 4,
            'coefficient' => 2,
            'actif' => true,
        ]);

        $this->matiereIaL3 = Matiere::create([
            'ue_id' => $this->ueInfoL3->id,
            'code' => 'MAT-IA-L3',
            'nom' => 'Intelligence Artificielle',
            'filiere' => 'Informatique',
            'niveau' => 'L3',
            'semestre' => 5,
            'credits' => 4,
            'coefficient' => 2,
            'actif' => true,
        ]);

        $this->groupeL2A = Group::create([
            'code' => 'G-L2-INFO-A',
            'nom' => 'Groupe L2 Info A',
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $this->programmeInfo->id,
            'niveau_id' => $this->niveauL2->id,
            'niveau' => 'L2',
            'filiere' => 'Informatique',
            'capacite' => 35,
            'actif' => true,
        ]);

        $this->groupeL2B = Group::create([
            'code' => 'G-L2-INFO-B',
            'nom' => 'Groupe L2 Info B',
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $this->programmeInfo->id,
            'niveau_id' => $this->niveauL2->id,
            'niveau' => 'L2',
            'filiere' => 'Informatique',
            'capacite' => 35,
            'actif' => true,
        ]);

        $this->groupeL3A = Group::create([
            'code' => 'G-L3-INFO-A',
            'nom' => 'Groupe L3 Info A',
            'faculte_id' => $this->faculteA->id,
            'programme_id' => $this->programmeInfo->id,
            'niveau_id' => $this->niveauL3->id,
            'niveau' => 'L3',
            'filiere' => 'Informatique',
            'capacite' => 35,
            'actif' => true,
        ]);

        $this->groupeFaculteB = Group::create([
            'code' => 'G-L2-GEST-A',
            'nom' => 'Groupe L2 Gestion A',
            'faculte_id' => $this->faculteB->id,
            'programme_id' => $this->programmeGestion->id,
            'niveau' => 'L2',
            'filiere' => 'Gestion',
            'capacite' => 35,
            'actif' => true,
        ]);

        $this->salleAmphi = Room::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'AMPHI-A',
            'nom' => 'Amphithéâtre A',
            'capacite' => 120,
            'type' => 'amphi',
            'actif' => true,
            'heure_ouverture' => '07:30:00',
            'heure_fermeture' => '19:30:00',
            'min_surveillants' => 2,
        ]);

        $this->surveillant1 = Teacher::create([
            'matricule' => 'ENS-CORR-01',
            'nom' => 'Mansouri',
            'prenom' => 'Karim',
            'email' => 'karim.mansouri@univ.test',
            'actif' => true,
        ]);

        $this->surveillant2 = Teacher::create([
            'matricule' => 'ENS-CORR-02',
            'nom' => 'Belkacem',
            'prenom' => 'Nadia',
            'email' => 'nadia.belkacem@univ.test',
            'actif' => true,
        ]);

        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculteA->id,
            'code' => 'SESS-CORR-2027',
            'nom' => 'Session Principale S3 2027',
            'type' => 'principale',
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-30',
            'statut' => 'open',
        ]);

        $this->examenL2 = Examen::create([
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programmeInfo->id,
            'niveau_id' => $this->niveauL2->id,
            'groupe_id' => $this->groupeL2A->id,
            'code' => 'EX-L2-INFO-S3',
            'libelle' => 'Examen Final L2 Informatique S3',
            'type' => 'examen',
            'statut' => 'scheduled',
        ]);

        $this->epreuveAlgoG1 = Epreuve::create([
            'code' => 'EPR-ALGO-L2A',
            'examen_id' => $this->examenL2->id,
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiereAlgoL2->id,
            'groupe_id' => $this->groupeL2A->id,
            'salle_id' => $this->salleAmphi->id,
            'date' => '2027-01-15',
            'heure_debut' => '09:00:00',
            'heure_fin' => '11:00:00',
            'duree_minutes' => 120,
            'capacite_necessaire' => 30,
            'statut' => 'scheduled',
        ]);

        Surveillance::create([
            'epreuve_id' => $this->epreuveAlgoG1->id,
            'teacher_id' => $this->surveillant1->id,
            'salle_id' => $this->salleAmphi->id,
            'role' => 'principal',
            'statut' => 'assigne',
        ]);

        Surveillance::create([
            'epreuve_id' => $this->epreuveAlgoG1->id,
            'teacher_id' => $this->surveillant2->id,
            'salle_id' => $this->salleAmphi->id,
            'role' => 'surveillant',
            'statut' => 'assigne',
        ]);

        $this->adminScolarite = User::creerCompteBackOffice([
            'name' => 'Admin Scolarité FST',
            'email' => 'admin.scol.fst@univ.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_scolarite');

        $this->adminFaculteB = User::creerCompteBackOffice([
            'name' => 'Admin Faculté B',
            'email' => 'admin.facb@univ.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');

        $this->utilisateurSansDroit = User::factory()->create([
            'name' => 'Utilisateur Sans Droit',
            'email' => 'lecteur.sansdroit@univ.test',
        ]);
    }

    /**
     * Crée un étudiant avec son inscription académique et éventuellement son inscription pédagogique.
     */
    private function creerEtudiantAvecInscriptions(
        string $matricule,
        string $nom,
        string $prenom,
        Group $groupe,
        ?Matiere $matiereInscrite = null,
        string $statutPedagogique = 'inscrit',
        string $statutAcademique = 'valide',
        string $statutEtudiant = 'valide',
    ): Etudiant {
        $etudiant = new Etudiant;
        $etudiant->forceFill([
            'num_inscription' => $matricule,
            'nom' => $nom,
            'prenom' => $prenom,
            'cin' => substr('C'.preg_replace('/[^0-9A-Z]/', '', $matricule), 0, 12),
            'date_naissance' => '2005-04-12',
            'sexe' => 'M',
            'adresse' => 'Campus Universitaire',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => 2024,
            'mention_bac' => 'Bien',
            'annee_academique' => '2026-2027',
            'email' => strtolower($matricule).'@etu.univ.test',
            'telephone' => '20100200',
            'niveau' => $groupe->niveau ?? 'L2',
            'programme_id' => $groupe->programme_id ?? $this->programmeInfo->id,
            'niveau_id' => $groupe->niveau_id ?? $this->niveauL2->id,
            'groupe_id' => $groupe->id,
            'faculte_id' => $groupe->faculte_id,
            'statut' => $statutEtudiant,
        ])->save();

        $inscriptionAcad = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'programme_id' => $groupe->programme_id ?? $this->programmeInfo->id,
            'niveau_id' => $groupe->niveau_id ?? $this->niveauL2->id,
            'niveau' => $groupe->niveau ?? 'L2',
            'filiere' => $groupe->filiere ?? 'Informatique',
            'groupe' => $groupe->code,
            'annee_universitaire_id' => $this->annee->id,
            'type' => 'premiere',
            'regime' => 'initiale',
            'paye' => true,
            'statut' => $statutAcademique,
        ]);

        if ($matiereInscrite !== null) {
            InscriptionPedagogique::create([
                'inscription_id' => $inscriptionAcad->id,
                'semestre_id' => $this->semestreS3->id,
                'ue_id' => $matiereInscrite->ue_id ?? $this->ueInfoL2->id,
                'matiere_id' => $matiereInscrite->id,
                'statut' => $statutPedagogique,
            ]);
        }

        return $etudiant;
    }

    /**
     * CAS 1 : Étudiant inscrit et éligible à la matière : accepté.
     */
    public function test_01_etudiant_inscrit_et_eligible_a_la_matiere_est_accepte(): void
    {
        $etudiant = $this->creerEtudiantAvecInscriptions(
            'ETU-CORR-001',
            'Benali',
            'Youssef',
            $this->groupeL2A,
            $this->matiereAlgoL2,
        );

        $crees = app(EpreuveService::class)->genererCandidats($this->epreuveAlgoG1);
        $this->assertSame(1, $crees);

        $analyse = app(CorrespondanceExamenService::class)->analyserEpreuve($this->epreuveAlgoG1->fresh());
        $this->assertTrue($analyse['conforme']);
        $this->assertSame(1, $analyse['compteurs']['inscrits']);
        $this->assertSame(1, $analyse['compteurs']['eligibles']);
        $this->assertSame(1, $analyse['compteurs']['planifies']);
        $this->assertSame(1, $analyse['compteurs']['convoques']);
        $this->assertSame(0, $analyse['compteurs']['exclus']);
        $this->assertTrue(Candidat::where('epreuve_id', $this->epreuveAlgoG1->id)->where('etudiant_id', $etudiant->id)->exists());
    }

    /**
     * CAS 2 : Étudiant non inscrit à la matière : rejeté (génération automatique ET ajout manuel).
     */
    public function test_02_etudiant_non_inscrit_a_la_matiere_est_rejete(): void
    {
        $inscrit = $this->creerEtudiantAvecInscriptions(
            'ETU-CORR-002A',
            'Haddad',
            'Amine',
            $this->groupeL2A,
            $this->matiereAlgoL2,
        );

        $nonInscrit = $this->creerEtudiantAvecInscriptions(
            'ETU-CORR-002B',
            'Mansour',
            'Sami',
            $this->groupeL2A,
            null, // Aucune inscription pédagogique à la matière
        );

        // 1. La génération automatique n'inclut que l'étudiant inscrit
        $crees = app(EpreuveService::class)->genererCandidats($this->epreuveAlgoG1);
        $this->assertSame(1, $crees);
        $this->assertTrue(Candidat::where('epreuve_id', $this->epreuveAlgoG1->id)->where('etudiant_id', $inscrit->id)->exists());
        $this->assertFalse(Candidat::where('epreuve_id', $this->epreuveAlgoG1->id)->where('etudiant_id', $nonInscrit->id)->exists());

        // 2. L'ajout manuel d'un étudiant non inscrit à la matière est rejeté côté serveur
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Ajout de candidat refusé');

        app(CorrespondanceExamenService::class)->ajouterCandidatAutorise(
            $this->epreuveAlgoG1,
            $nonInscrit,
            $this->adminScolarite->id
        );
    }

    /**
     * CAS 3 : Étudiant inscrit à une autre matière : rejeté et comptabilisé dans les exclus avec motif métier.
     */
    public function test_03_etudiant_inscrit_a_une_autre_matiere_est_rejete(): void
    {
        $etudiantAlgo = $this->creerEtudiantAvecInscriptions(
            'ETU-CORR-003A',
            'Trabelsi',
            'Ines',
            $this->groupeL2A,
            $this->matiereAlgoL2,
        );

        $etudiantAutreMatiere = $this->creerEtudiantAvecInscriptions(
            'ETU-CORR-003B',
            'Gharbi',
            'Mehdi',
            $this->groupeL2A,
            $this->matiereBddL2, // Inscrit uniquement à BDD, pas à ALGO
        );

        $crees = app(EpreuveService::class)->genererCandidats($this->epreuveAlgoG1);
        $this->assertSame(1, $crees);

        $analyse = app(CorrespondanceExamenService::class)->analyserEpreuve($this->epreuveAlgoG1->fresh());
        $this->assertTrue($analyse['conforme']);
        $this->assertSame(1, $analyse['compteurs']['eligibles']);
        $this->assertSame(1, $analyse['compteurs']['exclus']);
        $this->assertSame($etudiantAutreMatiere->id, $analyse['etudiants_exclus'][0]['etudiant_id']);
        $this->assertStringContainsString('Inscription pédagogique active', $analyse['etudiants_exclus'][0]['motif']);

        // Tentative d'ajout manuel de l'étudiant inscrit à une autre matière : rejeté
        $this->expectException(DomainException::class);
        app(CorrespondanceExamenService::class)->ajouterCandidatAutorise(
            $this->epreuveAlgoG1,
            $etudiantAutreMatiere,
            $this->adminScolarite->id
        );
    }

    /**
     * CAS 4 : Étudiant d'un groupe non concerné : rejeté lors de la génération et de l'ajout manuel.
     */
    public function test_04_etudiant_dun_groupe_non_concerne_est_rejete(): void
    {
        $etudiantGroupeA = $this->creerEtudiantAvecInscriptions(
            'ETU-CORR-004A',
            'Khelifi',
            'Rania',
            $this->groupeL2A,
            $this->matiereAlgoL2,
        );

        // Étudiant du groupe B pourtant inscrit à la matière mais non rattaché à l'épreuve du groupe A (sans regroupement virtuel)
        $etudiantGroupeB = $this->creerEtudiantAvecInscriptions(
            'ETU-CORR-004B',
            'Zouari',
            'Walid',
            $this->groupeL2B,
            $this->matiereAlgoL2,
        );

        $crees = app(EpreuveService::class)->genererCandidats($this->epreuveAlgoG1);
        $this->assertSame(1, $crees);
        $this->assertTrue(Candidat::where('epreuve_id', $this->epreuveAlgoG1->id)->where('etudiant_id', $etudiantGroupeA->id)->exists());
        $this->assertFalse(Candidat::where('epreuve_id', $this->epreuveAlgoG1->id)->where('etudiant_id', $etudiantGroupeB->id)->exists());

        // Tentative d'ajout manuel d'un étudiant d'un groupe non concerné : rejeté
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("n'appartient pas au(x) groupe(s) pédagogique(s) concerné(s)");

        app(CorrespondanceExamenService::class)->ajouterCandidatAutorise(
            $this->epreuveAlgoG1,
            $etudiantGroupeB,
            $this->adminScolarite->id
        );
    }

    /**
     * CAS 5 : Matière commune à plusieurs groupes : regroupement virtuel conforme autorisé.
     */
    public function test_05_matiere_commune_a_plusieurs_groupes_regroupement_conforme_autorise(): void
    {
        $etuA1 = $this->creerEtudiantAvecInscriptions('ETU-CORR-005A', 'Ayari', 'Lina', $this->groupeL2A, $this->matiereAlgoL2);
        $etuB1 = $this->creerEtudiantAvecInscriptions('ETU-CORR-005B', 'Baccouche', 'Omar', $this->groupeL2B, $this->matiereAlgoL2);
        // Étudiant de L2B non inscrit à la matière commune : doit rester exclu même après regroupement
        $etuB2NonInscrit = $this->creerEtudiantAvecInscriptions('ETU-CORR-005C', 'Chakroun', 'Nour', $this->groupeL2B, $this->matiereBddL2);

        $groupeVirtuel = app(GroupeVirtuelExamenService::class)->proposer(
            $this->epreuveAlgoG1,
            [$this->groupeL2A->id, $this->groupeL2B->id],
            $this->adminScolarite->id,
            'Regroupement L2 Info A+B — Algorithmique',
        );

        $this->assertNotNull($groupeVirtuel->id);

        $crees = app(EpreuveService::class)->genererCandidats($this->epreuveAlgoG1->fresh());
        $this->assertSame(2, $crees);

        $idsConvoques = Candidat::where('epreuve_id', $this->epreuveAlgoG1->id)->pluck('etudiant_id')->all();
        $this->assertContains($etuA1->id, $idsConvoques);
        $this->assertContains($etuB1->id, $idsConvoques);
        $this->assertNotContains($etuB2NonInscrit->id, $idsConvoques);

        $analyse = app(CorrespondanceExamenService::class)->analyserEpreuve($this->epreuveAlgoG1->fresh());
        $this->assertTrue($analyse['conforme']);
        $this->assertSame(2, $analyse['compteurs']['eligibles']);
        $this->assertSame(2, $analyse['compteurs']['planifies']);
        $this->assertSame(1, $analyse['compteurs']['exclus']);
    }

    /**
     * CAS 6 : Groupe virtuel : groupes d'origine et inscriptions académiques/pédagogiques strictement inchangés.
     */
    public function test_06_groupe_virtuel_groupes_origine_et_inscriptions_inchanges(): void
    {
        $etuA = $this->creerEtudiantAvecInscriptions('ETU-CORR-006A', 'Dridi', 'Salma', $this->groupeL2A, $this->matiereAlgoL2);
        $etuB = $this->creerEtudiantAvecInscriptions('ETU-CORR-006B', 'Ferjani', 'Tarek', $this->groupeL2B, $this->matiereAlgoL2);

        $snapshotAcadAvant = InscriptionAcademique::orderBy('id')->get(['id', 'etudiant_id', 'groupe', 'programme_id', 'niveau_id'])->toArray();
        $snapshotPedaAvant = InscriptionPedagogique::orderBy('id')->get(['id', 'inscription_id', 'matiere_id', 'statut'])->toArray();

        app(GroupeVirtuelExamenService::class)->proposer(
            $this->epreuveAlgoG1,
            [$this->groupeL2A->id, $this->groupeL2B->id],
            $this->adminScolarite->id
        );

        $simulation = app(ExamGroupeVirtuelService::class)->proposerGroupeVirtuel(
            $this->epreuveAlgoG1,
            [$this->groupeL2A->id, $this->groupeL2B->id]
        );
        $this->assertTrue($simulation['est_valide']);

        app(EpreuveService::class)->genererCandidats($this->epreuveAlgoG1->fresh());

        // Vérification d'immuabilité des groupes d'origine et des inscriptions
        $this->assertSame($this->groupeL2A->id, $etuA->fresh()->groupe_id);
        $this->assertSame($this->groupeL2A->code, $etuA->fresh()->groupe?->code);
        $this->assertSame($this->groupeL2B->id, $etuB->fresh()->groupe_id);
        $this->assertSame($this->groupeL2B->code, $etuB->fresh()->groupe?->code);
        $this->assertSame($snapshotAcadAvant, InscriptionAcademique::orderBy('id')->get(['id', 'etudiant_id', 'groupe', 'programme_id', 'niveau_id'])->toArray());
        $this->assertSame($snapshotPedaAvant, InscriptionPedagogique::orderBy('id')->get(['id', 'inscription_id', 'matiere_id', 'statut'])->toArray());
    }

    /**
     * CAS 7 : Étudiant éligible omis du planning : écart détecté et alerte explicite.
     */
    public function test_07_etudiant_eligible_omis_du_planning_ecart_detecte(): void
    {
        $etu1 = $this->creerEtudiantAvecInscriptions('ETU-CORR-007A', 'Jlassi', 'Meriem', $this->groupeL2A, $this->matiereAlgoL2);
        $etu2 = $this->creerEtudiantAvecInscriptions('ETU-CORR-007B', 'Kacem', 'Bilel', $this->groupeL2A, $this->matiereAlgoL2);

        // On ne planifie que le premier étudiant (le second est omis)
        Candidat::create([
            'epreuve_id' => $this->epreuveAlgoG1->id,
            'etudiant_id' => $etu1->id,
            'salle_id' => $this->salleAmphi->id,
            'statut' => 'non_pointe',
        ]);

        $analyse = app(CorrespondanceExamenService::class)->analyserEpreuve($this->epreuveAlgoG1->fresh());

        $this->assertFalse($analyse['conforme']);
        $this->assertCount(1, $analyse['ecarts']['eligibles_omis']);
        $this->assertSame($etu2->id, $analyse['ecarts']['eligibles_omis'][0]['etudiant_id']);
        $this->assertContains('1 étudiants éligibles sont absents de la planification.', $analyse['alertes']);
        $this->assertContains(CorrespondanceExamenService::ALERTE_PUBLICATION_BLOQUEE, $analyse['alertes']);
    }

    /**
     * CAS 8 : Candidat en double dans une épreuve : doublon empêché par contrainte et service.
     */
    public function test_08_candidat_en_double_dans_une_epreuve_doublon_empeche_et_signale(): void
    {
        $etu = $this->creerEtudiantAvecInscriptions('ETU-CORR-008', 'Oueslati', 'Rim', $this->groupeL2A, $this->matiereAlgoL2);

        $candidat1 = app(CorrespondanceExamenService::class)->ajouterCandidatAutorise(
            $this->epreuveAlgoG1,
            $etu,
            $this->adminScolarite->id
        );

        // Un second appel via le service métier est refusé pour empêcher tout doublon
        try {
            app(CorrespondanceExamenService::class)->ajouterCandidatAutorise(
                $this->epreuveAlgoG1,
                $etu,
                $this->adminScolarite->id
            );
            $this->fail("L'ajout d'un candidat en double aurait dû lever une DomainException.");
        } catch (DomainException $e) {
            $this->assertStringContainsString('Doublon interdit', $e->getMessage());
        }

        $this->assertSame(1, Candidat::where('epreuve_id', $this->epreuveAlgoG1->id)->where('etudiant_id', $etu->id)->count());

        // Et une insertion SQL brute en doublon est bloquée par l'index unique (epreuve_id, etudiant_id)
        $this->expectException(QueryException::class);
        Candidat::create([
            'epreuve_id' => $this->epreuveAlgoG1->id,
            'etudiant_id' => $etu->id,
            'statut' => 'non_pointe',
        ]);
    }

    /**
     * CAS 9 : Matière ou groupe modifié après génération : revalidation obligatoire et recalcul des candidats.
     */
    public function test_09_matiere_ou_groupe_modifie_apres_generation_revalidation_obligatoire(): void
    {
        $etuAlgo = $this->creerEtudiantAvecInscriptions('ETU-CORR-009A', 'Rezgui', 'Anis', $this->groupeL2A, $this->matiereAlgoL2);
        $etuBdd = $this->creerEtudiantAvecInscriptions('ETU-CORR-009B', 'Saidi', 'Farah', $this->groupeL2A, $this->matiereBddL2);

        // Validation initiale de la population pour la matière ALGO
        app(PreparationExamenService::class)->validerPopulation($this->epreuveAlgoG1, $this->adminScolarite->id);
        $this->assertNotNull($this->epreuveAlgoG1->fresh()->population_validated_at);
        $this->assertTrue(Candidat::where('epreuve_id', $this->epreuveAlgoG1->id)->where('etudiant_id', $etuAlgo->id)->exists());

        // Modification de la matière de l'épreuve vers BDD (même niveau/programme)
        $epreuveModifiee = app(EpreuveService::class)->modifier(
            $this->epreuveAlgoG1->fresh(),
            ['matiere_id' => $this->matiereBddL2->id],
            $this->adminScolarite->id
        );

        // 1. La validation de population est invalidée (revalidation obligatoire)
        $this->assertNull($epreuveModifiee->population_validated_at);
        $this->assertNull($epreuveModifiee->population_hash);

        // 2. La liste des candidats a été recalculée sur la nouvelle matière BDD
        $this->assertFalse(Candidat::where('epreuve_id', $epreuveModifiee->id)->where('etudiant_id', $etuAlgo->id)->exists());
        $this->assertTrue(Candidat::where('epreuve_id', $epreuveModifiee->id)->where('etudiant_id', $etuBdd->id)->exists());

        // 3. Si une inscription pédagogique change après validation de la population, la dérive est détectée et bloque la publication
        app(PreparationExamenService::class)->validerPopulation($epreuveModifiee, $this->adminScolarite->id);
        $this->creerEtudiantAvecInscriptions('ETU-CORR-009C', 'Tlili', 'Hichem', $this->groupeL2A, $this->matiereBddL2);

        $analyseApresDerive = app(CorrespondanceExamenService::class)->analyserEpreuve($epreuveModifiee->fresh(), exigerEligiblesPourPublication: true);
        $this->assertFalse($analyseApresDerive['conforme']);
        $this->assertContains('1 étudiants éligibles sont absents de la planification.', $analyseApresDerive['alertes']);
    }

    /**
     * CAS 10 : Discordance persistante : publication bloquée (épreuve ET examen).
     */
    public function test_10_discordance_persistante_bloque_la_publication(): void
    {
        $etuInscrit = $this->creerEtudiantAvecInscriptions('ETU-CORR-010A', 'Zribi', 'Sonia', $this->groupeL2A, $this->matiereAlgoL2);
        $etuNonInscrit = $this->creerEtudiantAvecInscriptions('ETU-CORR-010B', 'Abidi', 'Malek', $this->groupeL2A, $this->matiereBddL2);

        // Forcer un candidat non inscrit dans le planning
        Candidat::create([
            'epreuve_id' => $this->epreuveAlgoG1->id,
            'etudiant_id' => $etuInscrit->id,
            'salle_id' => $this->salleAmphi->id,
            'statut' => 'non_pointe',
        ]);
        Candidat::create([
            'epreuve_id' => $this->epreuveAlgoG1->id,
            'etudiant_id' => $etuNonInscrit->id,
            'salle_id' => $this->salleAmphi->id,
            'statut' => 'non_pointe',
        ]);

        $analyse = app(CorrespondanceExamenService::class)->analyserEpreuve($this->epreuveAlgoG1->fresh());
        $this->assertFalse($analyse['conforme']);
        $this->assertContains('1 étudiants planifiés ne sont pas inscrits à cette matière.', $analyse['alertes']);
        $this->assertContains(CorrespondanceExamenService::ALERTE_PUBLICATION_BLOQUEE, $analyse['alertes']);

        // 1. Blocage de la transition directe de l'épreuve vers 'published'
        try {
            app(EpreuveService::class)->transiter($this->epreuveAlgoG1->fresh(), 'published', $this->adminScolarite->id);
            $this->fail('La publication directe de l’épreuve incohérente aurait dû être bloquée.');
        } catch (DomainException $e) {
            $this->assertStringContainsString(CorrespondanceExamenService::ALERTE_PUBLICATION_BLOQUEE, $e->getMessage());
            $this->assertStringContainsString('1 étudiants planifiés ne sont pas inscrits à cette matière.', $e->getMessage());
        }

        // 2. Blocage de la publication de l'examen parent via ExamPublicationOrchestratorService
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('1 étudiants planifiés ne sont pas inscrits à cette matière.');

        app(ExamPublicationOrchestratorService::class)->publierExamen($this->examenL2, $this->adminScolarite->id);
    }

    /**
     * CAS 11 : Utilisateur non autorisé ou d'une autre faculté : accès refusé et anti-falsification d'identifiants.
     */
    public function test_11_utilisateur_non_autorise_ou_autre_faculte_acces_refuse(): void
    {
        $etu = $this->creerEtudiantAvecInscriptions('ETU-CORR-011', 'Bouazizi', 'Sarra', $this->groupeL2A, $this->matiereAlgoL2);
        app(EpreuveService::class)->genererCandidats($this->epreuveAlgoG1);

        // 1. Utilisateur sans permission : 403 sur l'écran d'épreuve et la génération de candidats
        $this->actingAs($this->utilisateurSansDroit)
            ->get(route('admin.evaluations.epreuves.show', $this->epreuveAlgoG1))
            ->assertForbidden();

        $this->actingAs($this->utilisateurSansDroit)
            ->post(route('admin.evaluations.epreuves.candidats', [$this->examenL2->id, $this->epreuveAlgoG1->id]))
            ->assertForbidden();

        // 2. Administrateur d'une autre faculté (Faculté B) : 404/403 (isolation par faculté)
        $this->actingAs($this->adminFaculteB)
            ->get(route('admin.evaluations.epreuves.show', $this->epreuveAlgoG1))
            ->assertNotFound();

        $this->actingAs($this->adminFaculteB)
            ->post(route('admin.evaluations.epreuves.transiter', [$this->epreuveAlgoG1, 'published']))
            ->assertNotFound();

        // 3. Tentative de falsification de groupe d'une autre faculté lors de la création d'épreuve : bloquée
        $this->expectException(DomainException::class);
        app(ExamScheduleEngineService::class)->planifierEpreuve([
            'examen_id' => $this->examenL2->id,
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiereAlgoL2->id,
            'groupe_id' => $this->groupeFaculteB->id,
            'salle_id' => $this->salleAmphi->id,
            'date' => '2027-01-16',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
        ]);
    }

    /**
     * CAS 12 : PDF, convocations et listes de salle : même liste de référence que l'épreuve validée.
     */
    public function test_12_pdf_convocations_et_listes_de_salle_concordent_avec_epreuve_validee(): void
    {
        $etuEligible1 = $this->creerEtudiantAvecInscriptions('ETU-CORR-012A', 'Chaieb', 'Yassine', $this->groupeL2A, $this->matiereAlgoL2);
        $etuEligible2 = $this->creerEtudiantAvecInscriptions('ETU-CORR-012B', 'Dhouib', 'Amina', $this->groupeL2A, $this->matiereAlgoL2);
        $etuNonInscrit = $this->creerEtudiantAvecInscriptions('ETU-CORR-012C', 'Ellouze', 'Karim', $this->groupeL2A, $this->matiereBddL2);

        app(PreparationExamenService::class)->validerPopulation($this->epreuveAlgoG1, $this->adminScolarite->id);
        app(ExamRoomAssignmentService::class)->affecterSalle($this->epreuveAlgoG1, $this->salleAmphi->id, $this->adminScolarite->id);

        // Même si une ligne parasite non éligible était injectée en base, les exports PDF la filtrent et la convocation individuelle la bloque
        $candidatParasite = Candidat::create([
            'epreuve_id' => $this->epreuveAlgoG1->id,
            'etudiant_id' => $etuNonInscrit->id,
            'salle_id' => $this->salleAmphi->id,
            'statut' => 'non_pointe',
        ]);

        // 1. Liste d'émargement PDF : contient exclusivement les 2 candidats de la liste de référence
        $reponseEmargement = app(ConvocationService::class)->listeEmargement($this->epreuveAlgoG1->fresh(), $this->salleAmphi);
        $contenuPdf = (string) $reponseEmargement->getContent();
        $this->assertStringContainsString('ETU-CORR-012A', $contenuPdf);
        $this->assertStringContainsString('ETU-CORR-012B', $contenuPdf);
        $this->assertStringNotContainsString('ETU-CORR-012C', $contenuPdf);

        // 2. Document professionnel (lot de convocations / répartition salle) : même liste de référence
        $donneesLot = app(ExamDocumentsProfessionnelsService::class)->preparerDonneesDocument(
            'lot_convocations_epreuve',
            ['epreuve_id' => $this->epreuveAlgoG1->id],
            $this->adminScolarite
        );
        $matriculesLot = array_column($donneesLot['lignes'], 'Matricule');
        $this->assertSame(['ETU-CORR-012A', 'ETU-CORR-012B'], $matriculesLot);

        // 3. Convocation individuelle autorisée pour un candidat éligible
        $candidatValide = Candidat::where('epreuve_id', $this->epreuveAlgoG1->id)->where('etudiant_id', $etuEligible1->id)->firstOrFail();
        $reponseConvoc = app(ConvocationService::class)->convocation($candidatValide);
        $this->assertSame(200, $reponseConvoc->getStatusCode());
        $this->assertStringContainsString('ETU-CORR-012A', (string) $reponseConvoc->getContent());

        // 4. Convocation individuelle bloquée pour le candidat non inscrit à la matière
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Convocation bloquée');
        app(ConvocationService::class)->convocation($candidatParasite);
    }

    /**
     * CAS 13 : Absence d'inscription pédagogique ou données incohérentes (matière sans éligibles, niveau/filière incompatible).
     */
    public function test_13_absence_inscription_pedagogique_ou_donnees_incoherentes_comportement_explicite_sur(): void
    {
        // Un étudiant existe à l'université mais n'est inscrit qu'à BDD (aucun étudiant inscrit à ALGO)
        $this->creerEtudiantAvecInscriptions('ETU-CORR-013', 'Feki', 'Leila', $this->groupeL2A, $this->matiereBddL2);

        // 1. Règle 5 : Une matière sans étudiants éligibles ne produit pas silencieusement un examen publiable
        $analyseSansEligibles = app(CorrespondanceExamenService::class)->analyserEpreuve($this->epreuveAlgoG1, exigerEligiblesPourPublication: true);
        $this->assertFalse($analyseSansEligibles['conforme']);
        $this->assertContains(
            'Aucun étudiant éligible pour cette matière : impossible de valider ou publier un examen sans candidats éligibles.',
            $analyseSansEligibles['bloquants']
        );

        try {
            app(EpreuveService::class)->transiter($this->epreuveAlgoG1, 'published', $this->adminScolarite->id);
            $this->fail('Une matière sans étudiants éligibles ne doit pas pouvoir être publiée.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Aucun étudiant éligible', $e->getMessage());
        }

        // 2. Règle 2 & 6 : Associer un groupe de niveau L3 à une épreuve de matière L2 est bloqué avec l'alerte métier
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(CorrespondanceExamenService::ALERTE_GROUPE_INCOHERENT);

        app(EpreuveService::class)->modifier(
            $this->epreuveAlgoG1,
            ['groupe_id' => $this->groupeL3A->id],
            $this->adminScolarite->id
        );
    }

    /**
     * CAS 14 : Concurrence, répétition d'une action (idempotence) et visibilité UX complète.
     */
    public function test_14_concurrence_et_repetition_action_aucune_incoherence_ni_duplication_indue(): void
    {
        $etu1 = $this->creerEtudiantAvecInscriptions('ETU-CORR-014A', 'Hamdi', 'Yasmine', $this->groupeL2A, $this->matiereAlgoL2);
        $etu2 = $this->creerEtudiantAvecInscriptions('ETU-CORR-014B', 'Jrad', 'Skander', $this->groupeL2A, $this->matiereAlgoL2);
        $etuExclu = $this->creerEtudiantAvecInscriptions('ETU-CORR-014C', 'Kammoun', 'Aziz', $this->groupeL2A, $this->matiereBddL2);

        // Exécutions répétées de la génération et de la validation de population
        $premierAppel = app(EpreuveService::class)->genererCandidats($this->epreuveAlgoG1);
        $deuxiemeAppel = app(EpreuveService::class)->genererCandidats($this->epreuveAlgoG1);
        $troisiemeAppel = app(EpreuveService::class)->genererCandidats($this->epreuveAlgoG1);

        $this->assertSame(2, $premierAppel);
        $this->assertSame(0, $deuxiemeAppel);
        $this->assertSame(0, $troisiemeAppel);
        $this->assertSame(2, Candidat::where('epreuve_id', $this->epreuveAlgoG1->id)->count());

        app(PreparationExamenService::class)->validerPopulation($this->epreuveAlgoG1, $this->adminScolarite->id);
        $anonymatsPremierPassage = Candidat::where('epreuve_id', $this->epreuveAlgoG1->id)
            ->orderBy('etudiant_id')
            ->pluck('numero_anonyme', 'etudiant_id')
            ->all();

        // Revalidation idempotente : conserve exactement les mêmes candidats et numéros anonymes
        app(PreparationExamenService::class)->validerPopulation($this->epreuveAlgoG1->fresh(), $this->adminScolarite->id);
        $anonymatsSecondPassage = Candidat::where('epreuve_id', $this->epreuveAlgoG1->id)
            ->orderBy('etudiant_id')
            ->pluck('numero_anonyme', 'etudiant_id')
            ->all();

        $this->assertSame($anonymatsPremierPassage, $anonymatsSecondPassage);
        $this->assertSame(2, Candidat::where('epreuve_id', $this->epreuveAlgoG1->id)->count());

        // Vérification UX (Section 4) sur la fiche de l'épreuve
        $reponseShow = $this->actingAs($this->adminScolarite)
            ->get(route('admin.evaluations.epreuves.show', $this->epreuveAlgoG1));

        $reponseShow->assertOk();
        $reponseShow->assertSee('Correspondance Pédagogique & Récapitulatif Pré-Publication', false);
        $reponseShow->assertSee('MAT-ALGO-L2');
        $reponseShow->assertSee('G-L2-INFO-A');
        $reponseShow->assertSee('Inscrits matière');
        $reponseShow->assertSee('Kammoun Aziz');
    }
}
