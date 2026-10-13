<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\Faculte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEC-12 (revue fichiers 12/10 — P1) : le contenu d'une maquette est une
 * donnée d'administrateur (admin_scolarite) consultée par des comptes à
 * privilèges supérieurs (super_admin, admin_faculte). Il ne doit JAMAIS
 * être rendu en HTML brut dans la page show : un attribut on* (hors
 * `<script`, donc non filtré) exécuterait du JS dans la session du
 * lecteur (CSP unsafe-inline sans frein). Le rendu réel reste assuré par
 * l'aperçu PDF — Dompdf n'exécute pas le JS.
 */
class MaquettesXssSecuriteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.xss@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    public function test_le_contenu_de_maquette_n_est_jamais_rendu_en_html_brut(): void
    {
        // Vecteur réel dénoncé par l'audit : attribut on* — passe le filtre
        // de contenu (aucun des 5 littéraux interdits n'est présent).
        $payload = [
            'type' => 'attestation_scolarite',
            'nom' => 'Modèle XSS',
            'locale' => 'fr',
            'contenu' => '<h1>{{ titreDocument }}</h1><img src=x onerror="window.__xss=\'pwned\'">',
            'schema' => '{"titreDocument": "Titre"}',
        ];

        $this->actingAs($this->admin)
            ->post(route('admin.maquettes.store'), $payload)
            ->assertRedirect();

        $maquette = DocumentTemplate::query()->where('nom', 'Modèle XSS')->firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('admin.maquettes.show', $maquette))
            ->assertOk()
            // Le tag n'est jamais rendu en HTML brut : aucun navigateur
            // ne peut l'interpréter, donc aucun script ne s'exécute.
            ->assertDontSee('<img src=x onerror', false)
            // Le contenu reste consultable, mais échappé (e() = ce que
            // produit exactement l'échappement Blade de la vue corrigée).
            ->assertSee(e('<img src=x onerror'), false);
    }
}
