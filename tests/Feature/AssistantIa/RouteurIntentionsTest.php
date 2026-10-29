<?php

namespace Tests\Feature\AssistantIa;

use App\Models\User;
use App\Modules\AssistantIa\DTO\ReponseIa;
use App\Modules\AssistantIa\Exceptions\SecuriteIaException;
use App\Modules\AssistantIa\Models\AiConversation;
use App\Modules\AssistantIa\Services\OrchestrateurAgentIa;
use App\Modules\AssistantIa\Services\PasserelleFournisseurs;
use App\Modules\AssistantIa\Services\RegistreOutils;
use App\Modules\AssistantIa\Services\RouteurIntentionsIa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteurIntentionsTest extends TestCase
{
    use RefreshDatabase;

    protected RouteurIntentionsIa $routeur;

    protected OrchestrateurAgentIa $orchestrateur;

    protected PasserelleFournisseurs $passerelle;

    protected RegistreOutils $registreOutils;

    protected User $superAdmin;

    protected User $agentScolarite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->routeur = app(RouteurIntentionsIa::class);
        $this->passerelle = app(PasserelleFournisseurs::class);
        $this->registreOutils = app(RegistreOutils::class);
        $this->orchestrateur = app(OrchestrateurAgentIa::class);

        $this->superAdmin = User::factory()->create([
            'role' => 'super_admin',
        ]);
        $this->superAdmin->setRelation('roleModel', null);

        $this->agentScolarite = User::factory()->create([
            'role' => 'agent_scolarite',
        ]);
        $this->agentScolarite->setRelation('roleModel', null);
    }

    public function test_classification_exacte_des_intentions_multiples(): void
    {
        // 1. Code query
        $resCode = $this->routeur->classifier('Quel contrôleur gère la validation des inscriptions ?', $this->superAdmin);
        $this->assertEquals('code_query', $resCode['intention']);
        $this->assertEquals('assistant_ia.mode_developpeur', $resCode['permission_requise']);

        // 2. Code patch
        $resPatch = $this->routeur->classifier('Propose un patch pour corriger la méthode de calcul', $this->superAdmin);
        $this->assertEquals('code_patch', $resPatch['intention']);
        $this->assertTrue($resPatch['necessite_approbation']);

        // 3. Diagnostic technique
        $resDiag = $this->routeur->classifier('J ai une QueryException 500 sur la table des notes', $this->superAdmin);
        $this->assertEquals('tech_diagnostic', $resDiag['intention']);

        // 4. Web search
        $resWeb = $this->routeur->classifier('Quelles sont les nouveautés de Laravel 11 sur les middlewares ?', $this->superAdmin);
        $this->assertEquals('web_search', $resWeb['intention']);
        $this->assertTrue($resWeb['necessite_web']);

        // 5. Action administrative sensible
        $resAction = $this->routeur->classifier('Valider le dossier étudiant numéro 452', $this->superAdmin);
        $this->assertEquals('action_prep', $resAction['intention']);
        $this->assertTrue($resAction['necessite_approbation']);

        // 6. Question métier LMD
        $resMetier = $this->routeur->classifier('Comment fonctionne la compensation annuelle des crédits ECTS en L2 ?', $this->superAdmin);
        $this->assertEquals('business_query', $resMetier['intention']);
    }

    public function test_rejet_action_non_autorisee_cote_serveur_selon_intention(): void
    {
        // L'agent de scolarité n'a pas la permission de proposer un patch de code
        $conv = AiConversation::create([
            'user_id' => $this->agentScolarite->id,
            'mode' => 'metier',
            'titre' => 'Test Intent',
        ]);

        $this->expectException(SecuriteIaException::class);
        $this->expectExceptionMessage("Action non autorisée : l'opération demandée requiert l'habilitation 'assistant_ia.action_proposer'");

        $this->orchestrateur->traiterMessage($conv, 'Propose un patch pour modifier le contrôleur', $this->agentScolarite);
    }

    public function test_orchestration_de_bout_en_bout_avec_routage_et_reponse_souveraine(): void
    {
        $conv = AiConversation::create([
            'user_id' => $this->superAdmin->id,
            'mode' => 'metier',
            'titre' => 'Test Flow',
        ]);

        $resultat = $this->orchestrateur->traiterMessage(
            $conv,
            'Quelles sont les conditions de validation d un semestre LMD ?',
            $this->superAdmin
        );

        $this->assertArrayHasKey('reponse', $resultat);
        $this->assertArrayHasKey('messageModele', $resultat);
        $this->assertInstanceOf(ReponseIa::class, $resultat['reponse']);
        $this->assertNotEmpty($resultat['messageModele']->contenu);
    }
}
