<?php

namespace Tests\Feature\AssistantIa;

use App\Models\User;
use App\Modules\AssistantIa\Models\AiDocumentIndex;
use App\Modules\AssistantIa\Models\AiSourceConnaissance;
use App\Modules\AssistantIa\Services\ObservabiliteIaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ObservabiliteEtJournalisationSecuriseeTest extends TestCase
{
    use RefreshDatabase;

    protected ObservabiliteIaService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ObservabiliteIaService;
    }

    public function test_metriques_knowledge_hub_calculent_les_totaux_correctement(): void
    {
        AiSourceConnaissance::create([
            'nom' => 'Source Active 1',
            'type' => 'project_code',
            'origine' => 'app/',
            'statut' => 'actif',
        ]);

        AiSourceConnaissance::create([
            'nom' => 'Source Suspendue',
            'type' => 'internal_document',
            'origine' => 'docs/',
            'statut' => 'suspendu',
        ]);

        AiSourceConnaissance::create([
            'nom' => 'Source Erreur',
            'type' => 'approved_web_source',
            'origine' => 'https://laravel.com/docs',
            'statut' => 'erreur',
        ]);

        AiDocumentIndex::create([
            'source' => 'app/Test.php',
            'chemin_fichier' => 'app/Test.php',
            'titre' => 'Test',
            'index_morceau' => 0,
            'contenu_morceau' => 'code',
            'empreinte_sha256' => hash('sha256', 'code'),
            'statut_document' => 'valide',
        ]);

        AiDocumentIndex::create([
            'source' => 'docs/Ancien.md',
            'chemin_fichier' => 'docs/Ancien.md',
            'titre' => 'Ancien',
            'index_morceau' => 0,
            'contenu_morceau' => 'ancien',
            'empreinte_sha256' => hash('sha256', 'ancien'),
            'statut_document' => 'obsolete',
        ]);

        $metriques = $this->service->metriquesKnowledgeHub();

        $this->assertEquals(3, $metriques['total_sources']);
        $this->assertEquals(1, $metriques['sources_actives']);
        $this->assertEquals(1, $metriques['sources_suspendues']);
        $this->assertEquals(1, $metriques['sources_erreur']);
        $this->assertEquals(33.3, $metriques['taux_echec_synchro_pct']);
        $this->assertEquals(2, $metriques['total_morceaux_indexes']);
        $this->assertEquals(1, $metriques['docs_obsoletes_exclus']);
    }

    public function test_expurgation_des_secrets_et_donnees_personnelles(): void
    {
        $payloadBrut = [
            'api_key' => 'sk-live-1234567890abcdef',
            'password' => 'MotDePasseTresSecret',
            'cin_etudiant' => '09876543',
            'authorization' => 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...',
            'description' => 'Contacter etudiant@universite.tn pour la CIN 12345678 avec Bearer secret-token-xyz',
            'param_normal' => 'parametre_technique_autorise',
        ];

        $payloadSanitise = $this->service->sanitiserDonneesPourJournal($payloadBrut);

        $this->assertEquals('[EXPURGÉ]', $payloadSanitise['api_key']);
        $this->assertEquals('[EXPURGÉ]', $payloadSanitise['password']);
        $this->assertEquals('[EXPURGÉ]', $payloadSanitise['cin_etudiant']);
        $this->assertEquals('[EXPURGÉ]', $payloadSanitise['authorization']);
        $this->assertEquals('parametre_technique_autorise', $payloadSanitise['param_normal']);

        // Vérification dans le texte libre
        $this->assertStringNotContainsString('etudiant@universite.tn', $payloadSanitise['description']);
        $this->assertStringContainsString('[EMAIL_EXPURGÉ]', $payloadSanitise['description']);
        $this->assertStringNotContainsString('12345678', $payloadSanitise['description']);
        $this->assertStringContainsString('[CIN_EXPURGÉ]', $payloadSanitise['description']);
        $this->assertStringNotContainsString('secret-token-xyz', $payloadSanitise['description']);
    }

    public function test_journalisation_evenement_securise_avec_contexte_technique(): void
    {
        $user = User::factory()->create();

        Log::shouldReceive('log')
            ->once()
            ->withArgs(function ($niveau, $message, $contexte) use ($user) {
                return $niveau === 'warning'
                    && str_contains($message, 'tentative_acces_interdit')
                    && $contexte['utilisateur_id'] === $user->id
                    && $contexte['api_key'] === '[EXPURGÉ]'
                    && ! str_contains(json_encode($contexte), 'secret123');
            });

        $resultat = $this->service->journaliserEvenementSecurise(
            evenement: 'tentative_acces_interdit',
            contexte: [
                'ressource' => 'docs/secrets.md',
                'api_key' => 'secret123',
            ],
            utilisateur: $user,
            niveau: 'warning'
        );

        $this->assertEquals('[EXPURGÉ]', $resultat['api_key']);
        $this->assertEquals($user->id, $resultat['utilisateur_id']);
    }
}
