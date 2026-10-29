<?php

namespace Tests\Feature\AssistantIa;

use App\Models\User;
use App\Modules\AssistantIa\Models\AiDocumentIndex;
use App\Modules\AssistantIa\Models\AiSourceConnaissance;
use App\Modules\AssistantIa\Services\MoteurRechercheDocumentaire;
use App\Modules\AssistantIa\Services\RegistreSourcesConnaissances;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SourcesMarkdownKnowledgeHubTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $agentScolarite;

    protected User $utilisateurConsultation;

    protected RegistreSourcesConnaissances $registre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'actif' => true,
        ]);

        $this->agentScolarite = User::factory()->create([
            'role' => 'agent_scolarite',
            'actif' => true,
        ]);

        $this->utilisateurConsultation = User::factory()->create([
            'role' => 'consultation',
            'actif' => true,
        ]);

        $this->registre = app(RegistreSourcesConnaissances::class);
    }

    public function test_initialiser_toutes_les_sources_enregistre_les_16_sources_du_projet(): void
    {
        $sources = $this->registre->initialiserToutesLesSources();

        $this->assertCount(16, $sources);
        $this->assertDatabaseHas('ai_sources_connaissances', ['origine' => 'docs', 'type' => 'business_procedure']);
        $this->assertDatabaseHas('ai_sources_connaissances', ['origine' => 'docs/architecture', 'type' => 'technical_documentation']);
        $this->assertDatabaseHas('ai_sources_connaissances', ['origine' => 'docs/engineering', 'type' => 'loop_report']);
        $this->assertDatabaseHas('ai_sources_connaissances', ['origine' => 'AGENTS.md', 'type' => 'technical_documentation']);
        $this->assertDatabaseHas('ai_sources_connaissances', ['origine' => 'CLAUDE.md', 'type' => 'technical_documentation']);
        $this->assertDatabaseHas('ai_sources_connaissances', ['origine' => 'MANUEL.md', 'type' => 'internal_document']);
        $this->assertDatabaseHas('ai_sources_connaissances', ['origine' => 'README.md', 'type' => 'technical_documentation']);
        $this->assertDatabaseHas('ai_sources_connaissances', ['origine' => 'LOOP_Inscription_Etudiants_Experience_Intelligente.md', 'type' => 'business_procedure']);
        $this->assertDatabaseHas('ai_sources_connaissances', ['origine' => 'LOOP_Portails_Enseignant_Etudiant_Laravel.md', 'type' => 'business_procedure']);
        $this->assertDatabaseHas('ai_sources_connaissances', ['origine' => 'gestion_academique_avancee_LMD_LOOPS.md', 'type' => 'business_procedure']);
        $this->assertDatabaseHas('ai_sources_connaissances', ['origine' => 'prompts_gestion_academique_avancee_LMD_LOOPS.md', 'type' => 'business_procedure']);
        $this->assertDatabaseHas('ai_sources_connaissances', ['origine' => 'loop_emploi.md', 'type' => 'business_procedure']);
        $this->assertDatabaseHas('ai_sources_connaissances', ['origine' => 'doc', 'type' => 'internal_document']);
        $this->assertDatabaseHas('ai_sources_connaissances', ['origine' => '.agents/skills', 'type' => 'technical_documentation']);
        $this->assertDatabaseHas('ai_sources_connaissances', ['origine' => '.opencode', 'type' => 'technical_documentation']);
        $this->assertDatabaseHas('ai_sources_connaissances', ['origine' => 'salima_import_csv_2026_2027/README_IMPORT.md', 'type' => 'business_procedure']);
    }

    public function test_acces_cockpit_sources_affiche_toutes_les_sources_et_kpi(): void
    {
        $this->registre->initialiserToutesLesSources();

        $response = $this->actingAs($this->superAdmin)->get(route('admin.assistant-ia.sources.index'));

        $response->assertOk();
        $response->assertViewIs('modules.assistant-ia.sources');
        $response->assertSee('Knowledge Hub UniRegistre');
        $response->assertSee('Conventions & Standards de Développement');
        $response->assertSee('Manuel Opérationnel UniRegistre');
        $response->assertSee('Spécifications Expérience Intelligente Inscription');
        $response->assertSee('Tout synchroniser (RAG)');
        $response->assertSee('Découvrir toutes les sources');
    }

    public function test_route_initialiser_sources_par_admin(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->post(route('admin.assistant-ia.sources.initialiser'));

        $response->assertRedirect();
        $response->assertSessionHas('succes');
        $this->assertDatabaseCount('ai_sources_connaissances', 16);
    }

    public function test_route_synchroniser_toutes_sources_par_admin(): void
    {
        // Enregistrer une source locale légère pour tester la synchro HTTP
        $this->registre->enregistrerSource(
            nom: 'Conventions Test',
            type: 'technical_documentation',
            origine: 'AGENTS.md'
        );

        $response = $this->actingAs($this->superAdmin)
            ->post(route('admin.assistant-ia.sources.synchroniser_tout'));

        $response->assertRedirect();
        $response->assertSessionHas('succes');
        $this->assertGreaterThan(0, AiDocumentIndex::count());
    }

    public function test_interdiction_acces_et_synchro_pour_utilisateurs_non_admin(): void
    {
        $responseGet = $this->actingAs($this->agentScolarite)
            ->get(route('admin.assistant-ia.sources.index'));
        $responseGet->assertForbidden();

        $responseInit = $this->actingAs($this->agentScolarite)
            ->post(route('admin.assistant-ia.sources.initialiser'));
        $responseInit->assertForbidden();

        $responseSync = $this->actingAs($this->agentScolarite)
            ->post(route('admin.assistant-ia.sources.synchroniser_tout'));
        $responseSync->assertForbidden();
    }

    public function test_commande_artisan_sync_sources(): void
    {
        $this->artisan('assistant-ia:sync-sources', ['--init' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('ai_sources_connaissances', 16);
    }

    public function test_rag_recherche_trouve_connaissances_dans_les_differentes_sources_md(): void
    {
        $this->registre->initialiserToutesLesSources();

        // Synchroniser spécifiquement AGENTS.md et KNOWLEDGE-BASE
        $sourceAgents = AiSourceConnaissance::where('origine', 'AGENTS.md')->firstOrFail();
        $this->registre->synchroniserSource($sourceAgents, true);

        $sourceArchitecture = AiSourceConnaissance::where('origine', 'docs/architecture')->firstOrFail();
        $this->registre->synchroniserSource($sourceArchitecture, true);

        $moteur = app(MoteurRechercheDocumentaire::class);

        // Recherche d'un concept présent dans AGENTS.md
        $resultats = $moteur->rechercher('OpenCode conventions French naming', $this->superAdmin, 5);

        $this->assertNotEmpty($resultats);
        $trouveAgents = false;
        foreach ($resultats as $res) {
            if (str_contains($res['chemin'], 'AGENTS.md') || str_contains($res['titre'], 'OpenCode')) {
                $trouveAgents = true;
                break;
            }
        }
        $this->assertTrue($trouveAgents, 'Le RAG doit retrouver les conventions de développement issues de AGENTS.md');
    }
}
