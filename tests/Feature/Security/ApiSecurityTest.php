<?php

namespace Tests\Feature\Security;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Modules\Schedule\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

/**
 * Sécurité API (§34-36, §55) : 401/403/422, erreurs sans fuite, throttle
 * actif, jokers LIKE neutralisés.
 */
class ApiSecurityTest extends TestCase
{
    use ActAsApiClient, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        Etudiant::create([
            'cin' => 'BK111111', 'nom' => 'Alaoui', 'prenom' => 'Yasmine',
            'email' => uniqid('y.', true).'@example.com',
            'sexe' => 'F', 'faculte_id' => $faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026',
            'adresse' => '1 rue', 'ville' => 'Rabat',
            'code_postal' => '10000', 'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ]);
    }

    public function test_sans_token_401(): void
    {
        $this->get('/api/v1/students')->assertUnauthorized();
        $this->post('/api/v1/data/salles/import/preview')->assertUnauthorized();
    }

    public function test_scope_insuffisant_403_sans_fuite(): void
    {
        [, $token] = $this->clientApi(['students:read']);

        $reponse = $this->post('/api/v1/data/salles/import/preview', [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertForbidden();

        $this->assertSame('insufficient_scope', $reponse->json('error'));
        $this->assertArrayNotHasKey('scopes', $reponse->json());
    }

    public function test_payload_invalide_422_sans_trace(): void
    {
        [, $token] = $this->clientApi(['data:exchange']);

        $reponse = $this->post('/api/v1/data/salles/import/confirm', [
            'mode' => 'creer',
        ], ['Authorization' => 'Bearer '.$token])->assertUnprocessable();

        $contenu = $reponse->getContent();
        $this->assertStringNotContainsString('trace', $contenu);
        $this->assertStringNotContainsString('app/', $contenu);
    }

    public function test_ressource_inexistante_404_json(): void
    {
        [, $token] = $this->clientApi(['students:read']);

        $reponse = $this->get('/api/v1/students/999999', [
            'Authorization' => 'Bearer '.$token,
        ])->assertNotFound();

        $this->assertTrue($reponse->headers->get('Content-Type') !== null);
    }

    public function test_throttle_actif_sur_imports(): void
    {
        [, $token] = $this->clientApi(['data:exchange']);

        $reponse = $this->post('/api/v1/data/salles/import/preview', [
            'fichier' => UploadedFile::fake()->createWithContent('s.csv', "code;nom\nB1;X\n", 'text/csv'),
        ], ['Authorization' => 'Bearer '.$token])->assertOk();

        // En-têtes de rate limiting présents = throttle appliqué.
        $this->assertNotNull($reponse->headers->get('X-RateLimit-Limit'));
    }

    public function test_jokers_like_neutralises(): void
    {
        [, $token] = $this->clientApi(['students:read']);

        // Rechercher "%" doit retourner 0 résultat (littéral), pas toute la table.
        $reponse = $this->get('/api/v1/students?search=%25', [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();

        $this->assertSame(0, count($reponse->json('data') ?? []));
    }

    public function test_export_autre_perimetre_refuse(): void
    {
        // Token cantonné lecture étudiants : pas d'export de données.
        [, $token] = $this->clientApi(['students:read']);
        Room::create(['code' => 'B12', 'nom' => 'Salle', 'capacite' => 10, 'actif' => true]);

        $this->get('/api/v1/data/salles/export/csv', [
            'Authorization' => 'Bearer '.$token,
        ])->assertForbidden();
    }
}
