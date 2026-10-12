<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\User;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\RegleEvaluation;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\DeliberationService;
use App\Modules\Evaluation\Services\RegleEvaluationService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EvaluationDeliberationPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Group $groupe;

    private Matiere $matiere1;

    private Matiere $matiere2;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences',
            'code' => 'FST',
            'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Scolarité',
            'email' => 'admin.scol@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'LGL',
            'nom' => 'Licence Génie Logiciel',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $niveau = Niveau::create(['programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);

        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id,
            'programme_id' => $this->programme->id,
            'code' => 'UE-INF1',
            'nom' => 'Informatique Fondamentale',
            'credits' => 6,
            'coefficient' => 2,
        ]);

        $this->matiere1 = Matiere::create([
            'code' => 'INFO-101',
            'nom' => 'Algorithmique',
            'ue_id' => $ue->id,
            'niveau' => 'L1',
            'semestre' => 1,
            'credits' => 3,
            'coefficient' => 1.5,
        ]);

        $this->matiere2 = Matiere::create([
            'code' => 'INFO-102',
            'nom' => 'Architecture',
            'ue_id' => $ue->id,
            'niveau' => 'L1',
            'semestre' => 1,
            'credits' => 3,
            'coefficient' => 1.5,
        ]);

        $this->groupe = Group::create([
            'code' => 'GL-G1',
            'nom' => 'Groupe 1',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'actif' => true,
        ]);

        RegleEvaluation::create([
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'version' => 1,
            'actif' => true,
            'seuil_validation' => 10.0,
            'seuil_rattrapage' => 8.0,
            'compensation_ecue' => true,
            'compensation_ue' => true,
            'compensation_semestre' => true,
            'arrondi' => '2dec',
        ]);
    }

    private function creerEtudiant(int $i): Etudiant
    {
        $etudiant = Etudiant::create([
            'nom' => 'Nom'.$i,
            'prenom' => 'Prenom'.$i,
            'cin' => sprintf('%08d', 10000000 + $i),
            'email' => "etudiant{$i}@universite.test",
            'telephone' => '980000'.sprintf('%02d', $i),
            'date_naissance' => '2004-01-01',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'groupe_id' => $this->groupe->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rue Universitaire',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2024',
            'password' => 'Etudiant@123',
            'statut' => 'valide',
            'num_inscription' => sprintf('ETU-2026-%04d', $i),
        ]);

        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
        ]);
        $semestre = $inscription->semestres()->create(['numero' => 1]);

        Note::create([
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'semestre_id' => $semestre->id,
            'matiere_id' => $this->matiere1->id,
            'note' => 12.0,
            'coefficient' => 1.5,
            'credits' => 3,
            'session' => 'normale',
        ]);

        Note::create([
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'semestre_id' => $semestre->id,
            'matiere_id' => $this->matiere2->id,
            'note' => 14.0,
            'coefficient' => 1.5,
            'credits' => 3,
            'session' => 'normale',
        ]);

        return $etudiant;
    }

    /**
     * DETTE N+1 SUR PRÉPARATION DE DÉLIBÉRATION :
     * Pour 10 étudiants, le nombre de requêtes SQL ne doit pas exploser (doit être < 25 requêtes).
     */
    public function test_deliberation_preparer_sans_n_plus_un(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->creerEtudiant($i);
        }

        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-2027-1',
            'nom' => 'Session 1',
            'type' => 'principale',
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-20',
            'statut' => 'open',
            'created_by' => $this->admin->id,
        ]);

        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id,
            'session_id' => $session->id,
            'programme_id' => $this->programme->id,
            'groupe_id' => $this->groupe->id,
            'statut' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        $service = app(DeliberationService::class);

        DB::enableQueryLog();
        $service->preparer($deliberation);
        $totalRequetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            25,
            $totalRequetes,
            "Préparation délibération pour 10 étudiants : {$totalRequetes} requêtes exécutées (N+1 interdit, attendu < 25)."
        );
        $this->assertSame(10, $deliberation->fresh()->decisions()->count());
    }

    /**
     * DETTE N+1 SUR RÉSOLUTION DES RÈGLES :
     * 100 résolutions successives de la même règle doivent être mémoïsées (1 seule requête SQL).
     */
    public function test_regle_evaluation_resolve_memoization(): void
    {
        $service = app(RegleEvaluationService::class);

        DB::enableQueryLog();
        for ($i = 0; $i < 50; $i++) {
            $service->resolve($this->annee->id, $this->programme->id);
        }
        $totalRequetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            2,
            $totalRequetes,
            "Résolution règle d'évaluation appelée 50 fois : {$totalRequetes} requêtes exécutées (mémoïsation attendue, <= 1)."
        );
    }

    /**
     * DETTE N+1 SUR L'ÉCRAN SHOW DÉLIBÉRATION :
     * Le chargement des jurys et décisions ne doit pas exécuter de requêtes N+1 sur membres.utilisateur / membres.enseignant.
     */
    public function test_deliberation_show_sans_n_plus_un(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->creerEtudiant($i);
        }

        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-2027-2',
            'nom' => 'Session 2',
            'type' => 'principale',
            'date_debut' => '2027-01-10',
            'date_fin' => '2027-01-20',
            'statut' => 'open',
            'created_by' => $this->admin->id,
        ]);

        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->annee->id,
            'session_id' => $session->id,
            'programme_id' => $this->programme->id,
            'groupe_id' => $this->groupe->id,
            'statut' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        app(DeliberationService::class)->preparer($deliberation);

        // Ajout de 3 membres de jury
        $teacher = Teacher::create([
            'matricule' => 'ENS-001',
            'prenom' => 'Ahmed',
            'nom' => 'Enseignant',
            'email' => 'ahmed.ens@test.ma',
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $deliberation->membres()->create(['user_id' => $this->admin->id, 'fonction' => 'president', 'present' => true]);
        $deliberation->membres()->create(['teacher_id' => $teacher->id, 'fonction' => 'assesseur', 'present' => true]);
        $deliberation->membres()->create(['user_id' => $this->admin->id, 'fonction' => 'secretaire', 'present' => false]);

        DB::enableQueryLog();
        $response = $this->actingAs($this->admin)->get(route('admin.evaluations.deliberations.show', $deliberation));
        $totalRequetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        $this->assertLessThan(
            20,
            $totalRequetes,
            "Affichage tableau délibération avec 3 membres et 5 décisions : {$totalRequetes} requêtes (attendu < 20)."
        );
    }
}
