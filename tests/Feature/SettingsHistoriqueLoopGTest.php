<?php

namespace Tests\Feature;

use App\Models\Parametre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LOOP G — Historique UI, requêtes bornées, accessibilité servie.
 */
class SettingsHistoriqueLoopGTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): void
    {
        $user = User::creerCompteBackOffice([
            'name' => 'Super', 'email' => uniqid('sg.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin');
        $this->actingAs($user);
    }

    public function test_historique_affiche_et_restauration(): void
    {
        $this->superAdmin();
        $cible = Parametre::where('cle', 'etablissement.nom')->first();

        $this->patch(route('admin.parametres.update', $cible), ['valeur' => 'Nom v2', 'attendu' => $cible->updated_at?->toIso8601String()])
            ->assertRedirect();

        $contenu = $this->get(route('admin.parametres.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Historique (1)', $contenu);
        $this->assertStringContainsString('Nom v2', $contenu);
        $this->assertStringContainsString('Restaurer', $contenu);
    }

    public function test_index_borne_en_requetes(): void
    {
        $this->superAdmin();
        $compteur = 0;
        DB::listen(function () use (&$compteur): void {
            $compteur++;
        });

        $this->get(route('admin.parametres.index'))->assertOk();
        $this->assertLessThan(15, $compteur, "Paramètres : {$compteur} requêtes.");
    }

    public function test_accessibilite_formulaires(): void
    {
        $this->superAdmin();
        $cibleAccessibilite = Parametre::where('cle', 'etablissement.nom')->first();
        $this->patch(
            route('admin.parametres.update', $cibleAccessibilite),
            ['valeur' => 'Nom accessibilité', 'attendu' => $cibleAccessibilite->updated_at?->toIso8601String()]
        )->assertRedirect();

        $contenu = $this->get(route('admin.parametres.index'))->assertOk()->getContent();

        // Marqueurs servis (erreurs absentes sur GET propre : role=alert
        // couvert par SettingsInterfaceLoopDTest après soumission invalide).
        $this->assertStringContainsString('role="search"', $contenu);
        $this->assertStringContainsString('<label', $contenu);
        $this->assertStringContainsString('<summary', $contenu);
    }
}
