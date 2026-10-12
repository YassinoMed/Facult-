<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

/**
 * Authentification API (§46-48) : token Sanctum par intégration,
 * scopes moindre privilège, révocation immédiate, bornes de pagination.
 */
class ApiAuthenticationTest extends TestCase
{
    use ActAsApiClient, RefreshDatabase;

    public function test_acces_sans_token_refuse(): void
    {
        $this->getJson('/api/v1/students')
            ->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_token_valide_authentifie_le_client(): void
    {
        [$client, $token] = $this->clientApi(['students:read']);
        Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'Test', 'active' => true]);

        $this->getApi('/api/v1/students', $token)
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_scope_insuffisant_refuse_l_acces(): void
    {
        // Token « lecture seule étudiants » : aucun droit d'export ni d'archive
        [, $token] = $this->clientApi(['students:read']);

        $this->postApi('/api/v1/exports', ['type' => 'students', 'format' => 'csv'], $token)
            ->assertStatus(403);

        // Étudiant existant : le contrôle de scope répond AVANT toute donnée
        $etudiant = $this->etudiantAvecFaculte();
        $this->getApi('/api/v1/students/'.$etudiant->id.'/academic-record', $token)
            ->assertStatus(403)
            ->assertJson(['message' => 'Le token utilisé ne dispose pas du scope requis.']);
    }

    public function test_scopes_multiples_autorisent_les_ressources_associees(): void
    {
        [, $token] = $this->clientApi(['students:read', 'archives:read', 'grades:read']);
        $etudiant = $this->etudiantAvecFaculte();

        $this->getApi("/api/v1/students/{$etudiant->id}", $token)->assertOk();
        $this->getApi("/api/v1/students/{$etudiant->id}/grades", $token)->assertOk();
        $this->getApi("/api/v1/students/{$etudiant->id}/academic-record", $token)->assertStatus(404); // pas de dossier : 404 propre, pas 403
    }

    public function test_token_revoque_est_immediatement_invalide(): void
    {
        [$client, $token] = $this->clientApi(['students:read']);

        // Le client consulte…
        $this->getApi('/api/v1/students', $token)->assertOk();

        // …puis son token est révoqué
        $client->tokens()->delete();
        $this->assertSame(0, $client->tokens()->count());

        // Requête fraîche (en production : nouvelle requête = nouvelle app)
        $this->resetAuthGuards();

        $this->getApi('/api/v1/students', $token)->assertStatus(401);
    }

    public function test_pagination_bornee_a_100_par_page(): void
    {
        [, $token] = $this->clientApi(['students:read']);
        Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'Test', 'active' => true]);

        // Tentative d'abuse : per_page=999999999 → borné à 100, jamais rejeté brutalement
        $reponse = $this->getApi('/api/v1/students?per_page=999999999', $token)->assertOk();

        $this->assertLessThanOrEqual(100, $reponse->json('meta.per_page') ?? 100);
    }

    public function test_health_public_sans_token(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJson(['status' => 'ok', 'version' => 'v1']);
    }

    private function etudiantAvecFaculte(): Etudiant
    {
        $faculte = Faculte::create(['nom' => 'Faculté Test', 'code' => 'FTEST', 'description' => 'Test', 'active' => true]);

        $etudiantCree = Etudiant::create(['cin' => 'APITEST'.random_int(100, 999),
            'nom' => 'Api', 'prenom' => 'Test', 'email' => uniqid('api.', true).'@example.com',
            'sexe' => 'M', 'faculte_id' => $faculte->id, 'niveau' => 'L1', 'annee_academique' => '2025-2026',
            'adresse' => 'Rue', 'ville' => 'Rabat', 'code_postal' => '10000', 'annee_bac' => '2024', ]);
        $etudiantCree->forceFill(['num_inscription' => 'INS-API-'.random_int(10000, 99999)])->save();

        return $etudiantCree;
    }
}
