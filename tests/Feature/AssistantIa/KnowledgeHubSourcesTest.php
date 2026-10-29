<?php

namespace Tests\Feature\AssistantIa;

use App\Modules\AssistantIa\Exceptions\AssistantIaException;
use App\Modules\AssistantIa\Exceptions\SecuriteIaException;
use App\Modules\AssistantIa\Models\AiSourceConnaissance;
use App\Modules\AssistantIa\Services\RegistreSourcesConnaissances;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeHubSourcesTest extends TestCase
{
    use RefreshDatabase;

    protected RegistreSourcesConnaissances $registre;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registre = app(RegistreSourcesConnaissances::class);
    }

    public function test_creation_et_mise_a_jour_idempotente_d_une_source(): void
    {
        $source = $this->registre->enregistrerSource(
            nom: 'Documentation Architecture',
            type: 'technical_documentation',
            origine: 'docs/architecture',
            description: 'Guides d’architecture UniRegistre'
        );

        $this->assertInstanceOf(AiSourceConnaissance::class, $source);
        $this->assertEquals('Documentation Architecture', $source->nom);
        $this->assertEquals('docs/architecture', $source->origine);
        $this->assertEquals('actif', $source->statut);
        $this->assertNotEmpty($source->uuid);

        // Mise à jour idempotente
        $sourceMaj = $this->registre->enregistrerSource(
            nom: 'Documentation Architecture v2',
            type: 'technical_documentation',
            origine: 'docs/architecture',
            description: 'Guides révisés'
        );

        $this->assertEquals($source->id, $sourceMaj->id);
        $this->assertEquals('Documentation Architecture v2', $sourceMaj->nom);
        $this->assertEquals(1, AiSourceConnaissance::where('origine', 'docs/architecture')->count());
    }

    public function test_rejet_type_invalide_ou_path_traversal(): void
    {
        $this->expectException(AssistantIaException::class);
        $this->registre->enregistrerSource(
            nom: 'Source Invalide',
            type: 'type_inconnu_xyz',
            origine: 'docs'
        );
    }

    public function test_rejet_path_traversal_sur_origine(): void
    {
        $this->expectException(SecuriteIaException::class);
        $this->registre->enregistrerSource(
            nom: 'Attaque Traversal',
            type: 'internal_document',
            origine: 'docs/../../etc'
        );
    }

    public function test_source_inaccessible_est_detectee_et_marquee_en_erreur(): void
    {
        $source = $this->registre->enregistrerSource(
            nom: 'Dossier Inexistant',
            type: 'internal_document',
            origine: 'dossier_totalement_inexistant_xyz123'
        );

        $disponible = $this->registre->verifierDisponibilite($source);
        $this->assertFalse($disponible);

        $resultat = $this->registre->synchroniserSource($source);
        $this->assertFalse($resultat['succes']);
        $this->assertTrue($source->fresh()->enErreur());
        $this->assertStringContainsString('inaccessible', $source->fresh()->derniere_erreur);
    }

    public function test_suspension_et_reactivation_d_une_source(): void
    {
        $source = $this->registre->enregistrerSource(
            nom: 'Source Suspendable',
            type: 'business_procedure',
            origine: 'docs'
        );

        $this->registre->suspendreSource($source);
        $this->assertTrue($source->fresh()->estSuspendue());

        $sync = $this->registre->synchroniserSource($source->fresh());
        $this->assertFalse($sync['succes']);
        $this->assertStringContainsString('suspendue', $sync['message']);

        $this->registre->reactiverSource($source);
        $this->assertTrue($source->fresh()->estActive());
    }

    public function test_desactivation_reversive_preservant_les_preuves_d_audit(): void
    {
        $source = $this->registre->enregistrerSource(
            nom: 'Source Temporaire',
            type: 'loop_report',
            origine: 'docs/engineering'
        );

        $id = $source->id;
        $this->registre->desactiverSource($source);

        $this->assertNull(AiSourceConnaissance::find($id));
        $this->assertNotNull(AiSourceConnaissance::withTrashed()->find($id));
    }

    public function test_erreur_enregistree_sans_fuite_de_secrets(): void
    {
        $source = $this->registre->enregistrerSource(
            nom: 'Source Erreur Avec Secret',
            type: 'internal_document',
            origine: 'docs'
        );

        // Simulation d'une exception contenant une clé API ou un token sensible
        $erreurAvecSecret = 'Échec connexion avec cle_api=sk-1234567890abcdef1234567890abcdef et mot_de_passe: Secret123!';
        $source->marquerErreur($erreurAvecSecret);

        $erreurStockee = $source->fresh()->derniere_erreur;
        $this->assertStringNotContainsString('sk-1234567890abcdef1234567890abcdef', $erreurStockee);
        $this->assertStringNotContainsString('Secret123!', $erreurStockee);
        $this->assertTrue(
            str_contains($erreurStockee, '[CLÉ_API_MASQUÉE]') ||
            str_contains($erreurStockee, '[MASQUÉ]') ||
            str_contains($erreurStockee, '[DONNÉE_SENSIBLE_MASQUÉE]')
        );
    }

    public function test_initialisation_des_sources_fondamentales(): void
    {
        $this->registre->initialiserSourcesFondamentales();

        $this->assertGreaterThanOrEqual(3, AiSourceConnaissance::count());
        $this->assertNotNull(AiSourceConnaissance::where('type', 'business_procedure')->first());
        $this->assertNotNull(AiSourceConnaissance::where('type', 'loop_report')->first());
        $this->assertNotNull(AiSourceConnaissance::where('type', 'technical_documentation')->first());
    }
}
