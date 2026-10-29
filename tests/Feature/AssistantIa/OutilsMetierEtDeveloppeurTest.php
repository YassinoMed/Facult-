<?php

namespace Tests\Feature\AssistantIa;

use App\Models\User;
use App\Modules\AssistantIa\Services\RegistreOutils;
use App\Modules\AssistantIa\Tools\Commun\ConsulterDocumentationWebTool;
use App\Modules\AssistantIa\Tools\Metier\ExpliquerReferentielLmdTool;
use App\Modules\AssistantIa\Tools\Metier\VerifierReglesEvaluationTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OutilsMetierEtDeveloppeurTest extends TestCase
{
    use RefreshDatabase;

    protected RegistreOutils $registre;

    protected User $superAdmin;

    protected User $agentMetier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registre = app(RegistreOutils::class);

        $this->superAdmin = User::factory()->make([
            'id' => 110,
            'role' => 'super_admin',
        ]);
        $this->superAdmin->setRelation('roleModel', null);

        $this->agentMetier = User::factory()->make([
            'id' => 111,
            'role' => 'agent_scolarite',
        ]);
        $this->agentMetier->setRelation('roleModel', null);
    }

    public function test_registre_contient_tous_les_outils_requis(): void
    {
        $this->assertTrue($this->registre->existe('consulter_routes'));
        $this->assertTrue($this->registre->existe('rechercher_fichiers_code'));
        $this->assertTrue($this->registre->existe('proposer_patch'));
        $this->assertTrue($this->registre->existe('expliquer_cycle_inscription'));
        $this->assertTrue($this->registre->existe('diagnostiquer_blocage_dossier'));
        $this->assertTrue($this->registre->existe('verifier_pieces_manquantes'));
        $this->assertTrue($this->registre->existe('consulter_statut_tache'));
        $this->assertTrue($this->registre->existe('preparer_action_scolarite'));
        $this->assertTrue($this->registre->existe('consulter_documentation_web'));
        $this->assertTrue($this->registre->existe('expliquer_referentiel_lmd'));
        $this->assertTrue($this->registre->existe('verifier_regles_evaluation'));
        $this->assertTrue($this->registre->existe('consulter_eligibilite_examen'));
        $this->assertTrue($this->registre->existe('consulter_produit_data_mesh'));
        $this->assertTrue($this->registre->existe('consulter_actions_disponibles'));

        $this->assertCount(14, $this->registre->tous());
    }

    public function test_outil_web_consulte_page_autorisee_et_rejette_url_invalide(): void
    {
        $outil = app(ConsulterDocumentationWebTool::class);

        Http::fake([
            'https://laravel.com/docs/11.x/routing' => Http::response(
                '<html><head><title>Routing - Laravel</title></head><body>Basic routing in Laravel.</body></html>',
                200
            ),
        ]);

        $resSucces = $outil->executer(['url' => 'https://laravel.com/docs/11.x/routing'], $this->superAdmin);
        $this->assertTrue($resSucces->succes);
        $this->assertNotEmpty($resSucces->sources);

        // Échec sur URL manquante
        $resEchec = $outil->executer([], $this->superAdmin);
        $this->assertFalse($resEchec->succes);

        // Échec sur domaine non autorisé
        $resInterdit = $outil->executer(['url' => 'https://unauthorized-domain.com/secret'], $this->superAdmin);
        $this->assertFalse($resInterdit->succes);
    }

    public function test_outil_lmd_explique_credits_ects_et_semestres(): void
    {
        $outil = app(ExpliquerReferentielLmdTool::class);

        $resEcts = $outil->executer(['sujet' => 'credits_ects'], $this->superAdmin);
        $this->assertTrue($resEcts->succes);
        $this->assertStringContainsString('30 crédits ECTS', $resEcts->donnees['description']);

        $resSemestre = $outil->executer(['sujet' => 'structure_semestre'], $this->superAdmin);
        $this->assertTrue($resSemestre->succes);
        $this->assertStringContainsString('compensation semestrielle', $resSemestre->donnees['regle_compensation']);
    }

    public function test_outil_evaluation_explique_regles_de_compensation_et_rattrapage(): void
    {
        $outil = app(VerifierReglesEvaluationTool::class);

        $resComp = $outil->executer(['type_regle' => 'compensation_annuelle', 'niveau' => 'L2'], $this->superAdmin);
        $this->assertTrue($resComp->succes);
        $this->assertStringContainsString('10.00/20', $resComp->donnees['condition']);

        $resDette = $outil->executer(['type_regle' => 'passage_avec_dette', 'niveau' => 'L1'], $this->superAdmin);
        $this->assertTrue($resDette->succes);
        $this->assertStringContainsString('45 crédits ECTS', $resDette->donnees['condition']);
    }

    public function test_separation_stricte_lecture_et_ecriture_sensible(): void
    {
        // Seul preparer_action_scolarite déclenche une mutation sensible exigeant approbation humaine
        foreach ($this->registre->tous() as $nom => $outil) {
            if ($nom === 'preparer_action_scolarite') {
                $this->assertTrue($outil->estSensible(), "L'outil '{$nom}' doit être sensible.");
            } else {
                $this->assertFalse($outil->estSensible(), "L'outil '{$nom}' doit être en lecture seule (non sensible).");
            }
        }
    }
}
