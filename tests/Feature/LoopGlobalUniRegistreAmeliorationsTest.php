<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\AssistantIa\DTO\ResultatOutil;
use App\Modules\AssistantIa\Exceptions\SecuriteIaException;
use App\Modules\AssistantIa\Tools\Metier\ComparerScenariosD04Tool;
use App\Modules\AssistantIa\Tools\Metier\ConsulterDerivesSessionTool;
use App\Modules\AssistantIa\Tools\Metier\ExpliquerExclusionsEpreuveTool;
use App\Modules\AssistantIa\Tools\Metier\ResumerAnomaliesPublicationTool;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\GroupeVirtuelExamen;
use App\Modules\Evaluation\Models\GroupeVirtuelMembre;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoopGlobalUniRegistreAmeliorationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $adminFaculte;

    private Faculte $faculte;

    private Faculte $autreFaculte;

    private AnneeUniversitaire $annee;

    private SessionExamen $session;

    private Programme $programme;

    private SemestreLmd $semestre;

    private UniteEnseignement $ue;

    private Matiere $matiere;

    private Epreuve $epreuve;

    private Group $groupeSource;

    private GroupeVirtuelExamen $groupeVirtuel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['code' => 'FST', 'nom' => 'Faculté des Sciences']);
        $this->autreFaculte = Faculte::create(['code' => 'FMP', 'nom' => 'Faculté de Médecine']);

        $this->admin = User::factory()->create([
            'role' => 'super_admin',
            'email' => 'admin@universite.ac.ma',
            'faculte_id' => null,
        ]);

        $this->adminFaculte = User::factory()->create([
            'role' => 'admin_faculte',
            'email' => 'admin.fac@universite.ac.ma',
            'faculte_id' => $this->faculte->id,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'code' => '2025-2026',
            'libelle' => '2025-2026',
            'actif' => true,
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
        ]);
        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'S1-2026',
            'nom' => 'Session Principale S1',
            'type' => 'principale',
            'statut' => 'ouverte',
            'date_debut' => now()->toDateString(),
            'date_fin' => now()->addDays(10)->toDateString(),
        ]);

        $this->programme = Programme::create([
            'code' => 'PROG-INFO',
            'nom' => 'Informatique',
            'faculte_id' => $this->faculte->id,
        ]);

        $niveau = Niveau::create([
            'programme_id' => $this->programme->id,
            'code' => 'L1',
            'nom' => 'Licence 1',
            'ordre' => 1,
        ]);

        $this->semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id,
            'code' => 'S1',
            'nom' => 'Semestre 1',
            'numero' => 1,
            'ordre' => 1,
        ]);

        $this->ue = UniteEnseignement::create([
            'code' => 'UE-ALGO',
            'nom' => 'UE Algorithmique',
            'programme_id' => $this->programme->id,
            'semestre_id' => $this->semestre->id,
            'credits' => 6,
        ]);

        $this->matiere = Matiere::create([
            'code' => 'MAT-ALGO',
            'nom' => 'Algorithmique Avancée',
            'ue_id' => $this->ue->id,
            'niveau' => 'L1',
            'semestre' => 1,
            'credits' => 3,
            'actif' => true,
        ]);

        $salle = Room::create(['code' => 'AMPHI-A', 'nom' => 'Grand Amphithéâtre A', 'capacite' => 150]);

        $examen = Examen::create([
            'code' => 'EX-INFO-01',
            'libelle' => 'Examen Algorithmique',
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'statut' => 'planned',
        ]);

        $this->epreuve = Epreuve::create([
            'code' => 'EPR-ALGO-01',
            'examen_id' => $examen->id,
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $this->matiere->id,
            'salle_id' => $salle->id,
            'date' => now()->addDays(2)->toDateString(),
            'heure_debut' => '09:00:00',
            'heure_fin' => '11:00:00',
            'statut' => 'scheduled',
        ]);

        $this->groupeSource = Group::create([
            'code' => 'GRP-INFO-L1',
            'nom' => 'L1 Info Grp 1',
            'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'type' => 'TD',
            'capacite' => 30,
            'actif' => true,
        ]);

        $this->groupeVirtuel = GroupeVirtuelExamen::create([
            'examen_id' => $examen->id,
            'epreuve_id' => $this->epreuve->id,
            'matiere_id' => $this->matiere->id,
            'programme_id' => $this->programme->id,
            'libelle' => 'GV Algorithmique Avancée',
            'cle' => hash('sha256', 'GV-ALGO-1'),
            'statut' => GroupeVirtuelExamen::STATUT_PROPOSE,
            'sources' => [$this->groupeSource->id],
            'cree_par' => $this->admin->id,
        ]);

        // Création de 2 étudiants éligibles et 1 non éligible
        for ($i = 1; $i <= 3; $i++) {
            $cin = 'BK'.str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            $etu = Etudiant::create([
                'cin' => $cin,
                'num_inscription' => 'ETU-'.$cin,
                'nom' => 'Nom'.$i,
                'prenom' => 'Prenom'.$i,
                'email' => strtolower($cin).'@univ.test',
                'date_naissance' => '2004-05-15',
                'sexe' => 'M',
                'faculte_id' => $this->faculte->id,
                'programme_id' => $this->programme->id,
                'groupe_id' => $this->groupeSource->id,
                'niveau' => 'L1',
                'annee_academique' => '2025-2026',
                'adresse' => 'Campus Universitaire',
                'ville' => 'Tunis',
                'code_postal' => '1002',
                'annee_bac' => 2024,
                'password' => 'Etudiant@123',
            ]);
            $etu->definirStatut('valide');

            $inscription = $etu->inscriptionsAcademiques()->create([
                'annee_universitaire_id' => $this->annee->id,
                'programme_id' => $this->programme->id,
                'niveau' => 'L1',
                'statut' => 'en_cours',
            ]);

            $estEligible = $i <= 2;
            if ($estEligible) {
                $inscription->pedagogiques()->create([
                    'semestre_id' => $this->semestre->id,
                    'ue_id' => $this->ue->id,
                    'matiere_id' => $this->matiere->id,
                    'statut' => 'inscrit',
                ]);
            }

            GroupeVirtuelMembre::create([
                'groupe_virtuel_id' => $this->groupeVirtuel->id,
                'etudiant_id' => $etu->id,
                'groupe_source_id' => $this->groupeSource->id,
                'decision' => $estEligible ? GroupeVirtuelMembre::DECISION_ELIGIBLE : GroupeVirtuelMembre::DECISION_NON_ELIGIBLE,
                'motif' => $estEligible ? null : 'Non inscrit au module pédagogique',
                'regle' => 'inscription_module_active',
            ]);
        }
    }

    public function test_centre_anomalies_affiche_les_anomalies_detectees(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.evaluation.examens.groupes-virtuels.anomalies'));

        $response->assertStatus(200);
        $response->assertSee('Centre des anomalies des groupes virtuels');
        $response->assertSee('Total anomalies');
    }

    public function test_resynchroniser_selection_groupee(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('admin.evaluation.examens.groupes-virtuels.resynchroniser-selection'), [
                'ids' => [$this->groupeVirtuel->id],
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('succes');

        // L'ancien groupe a été archivé en "remplace"
        $this->assertEquals(GroupeVirtuelExamen::STATUT_REMPLACE, $this->groupeVirtuel->fresh()->statut);
        // Un nouveau groupe a été créé
        $this->assertDatabaseHas('evaluation_groupes_virtuels', [
            'epreuve_id' => $this->epreuve->id,
            'statut' => GroupeVirtuelExamen::STATUT_PROPOSE,
        ]);
    }

    public function test_commande_verifier_derives_capacites_s_execute_avec_succes(): void
    {
        $this->artisan('evaluation:verifier-derives-capacites')
            ->assertExitCode(0);

        $this->assertDatabaseHas('integration_logs', [
            'integration' => 'evaluation_automated_check',
            'direction' => 'internal',
            'operation' => 'verification_derives_capacites',
        ]);
    }

    public function test_outil_assistant_consulter_derives_session(): void
    {
        $tool = app(ConsulterDerivesSessionTool::class);
        $result = $tool->executer(['session_id' => $this->session->id], $this->admin);

        $this->assertInstanceOf(ResultatOutil::class, $result);
        $this->assertTrue($result->succes);
        $this->assertArrayHasKey('total_groupes', $result->donnees);
        $this->assertArrayHasKey('groupes_en_derive', $result->donnees);
        $this->assertNotEmpty($result->sources);
    }

    public function test_outil_assistant_expliquer_exclusions_epreuve_respecte_zero_pii(): void
    {
        $tool = app(ExpliquerExclusionsEpreuveTool::class);
        $result = $tool->executer(['epreuve_id' => $this->epreuve->id], $this->admin);

        $this->assertInstanceOf(ResultatOutil::class, $result);
        $this->assertTrue($result->succes);
        $this->assertEquals(2, $result->donnees['effectif_eligible']);
        $this->assertEquals(1, $result->donnees['effectif_exclu']);
        $this->assertArrayHasKey('Non inscrit au module pédagogique', $result->donnees['repartition_par_motif']);
    }

    public function test_outil_assistant_comparer_scenarios_d04(): void
    {
        $tool = app(ComparerScenariosD04Tool::class);
        $result = $tool->executer(['epreuve_id' => $this->epreuve->id], $this->admin);

        $this->assertInstanceOf(ResultatOutil::class, $result);
        $this->assertTrue($result->succes);
        $this->assertArrayHasKey('salle_actuelle', $result->donnees);
        $this->assertArrayHasKey('scenarios', $result->donnees);
    }

    public function test_outil_assistant_resumer_anomalies_publication(): void
    {
        $tool = app(ResumerAnomaliesPublicationTool::class);
        $result = $tool->executer(['examen_id' => $this->epreuve->examen_id], $this->admin);

        $this->assertInstanceOf(ResultatOutil::class, $result);
        $this->assertTrue($result->succes);
        $this->assertFalse($result->donnees['est_publiable']);
        $this->assertNotEmpty($result->donnees['anomalies_bloquantes']);
    }

    public function test_outils_assistant_respectent_isolation_facultaire_bola(): void
    {
        $autreUserFaculte = User::factory()->create([
            'role' => 'admin_faculte',
            'email' => 'autre.fac@universite.ac.ma',
            'faculte_id' => $this->autreFaculte->id,
        ]);

        $tool = app(ExpliquerExclusionsEpreuveTool::class);

        $this->expectException(SecuriteIaException::class);
        $tool->executer(['epreuve_id' => $this->epreuve->id], $autreUserFaculte);
    }
}
