<?php

namespace Tests\Feature\Security;

use App\Models\Etudiant;
use App\Models\Faculte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\ActAsApiClient;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * Durcissement transversal (mission 12/10/2026, phase 5) : contrôles
 * complémentaires non dupliqués — CSP publique, mass assignment modèle,
 * invité sur fichier privé, throttle configuré, scope API.
 */
class DurcissementTransversalTest extends TestCase
{
    use ActAsApiClient, ActAsScolarite, RefreshDatabase;

    public function test_csp_publique_contient_connect_src(): void
    {
        $csp = (string) $this->get(route('inscription.create'))->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("connect-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    public function test_mass_assignment_statut_ignore_a_la_creation(): void
    {
        $faculte = Faculte::create([
            'nom' => 'FST test', 'code' => 'FST-'.strtoupper(substr(md5(uniqid('', true)), 0, 4)),
            'description' => 'T', 'active' => true,
        ]);

        $etudiant = Etudiant::create([
            'cin' => 'BK'.random_int(100000, 999999),
            'nom' => 'Test', 'prenom' => 'Mass',
            'email' => uniqid('mass.', true).'@example.com',
            'sexe' => 'M', 'faculte_id' => $faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026',
            'adresse' => '1 rue', 'ville' => 'Rabat',
            'code_postal' => '10000', 'annee_bac' => '2024',
            'password' => 'motdepasse1',
            'statut' => 'valide',
            'num_inscription' => 'INS-FORGE-1',
        ]);

        $this->assertSame('en_attente', $etudiant->statut);
        $this->assertNotSame('INS-FORGE-1', $etudiant->num_inscription);
    }

    public function test_invite_redirige_sur_telechargement_prive(): void
    {
        $etudiant = $this->etudiantAvecDossier();

        $this->get(route('admin.archives.documents.download', ['document' => 999999]))
            ->assertRedirect(route('admin.connexion.show'));
        $this->assertGuest();
        $this->assertNotNull($etudiant->id);
    }

    public function test_throttle_connexion_admin_configure(): void
    {
        $route = collect(Route::getRoutes())
            ->first(fn ($r) => $r->getName() === 'admin.connexion.login');

        $this->assertNotNull($route);
        $this->assertContains('throttle:5,1', $route->gatherMiddleware());
    }

    public function test_api_export_sensible_sans_scope_refuse(): void
    {
        [, $token] = $this->clientApi(['students:read']);

        $reponse = $this->post('/api/v1/exports', [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertForbidden();

        $this->assertSame('insufficient_scope', $reponse->json('error'));
    }
}
