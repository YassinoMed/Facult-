<?php

namespace Tests\Feature;

use App\Models\Parametre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP D — Interface paramètres : recherche, champs par type, défauts,
 * erreurs visibles, traçabilité d'auteur.
 */
class SettingsInterfaceLoopDTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): void
    {
        $user = User::creerCompteBackOffice([
            'name' => 'Super', 'email' => uniqid('sd.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin');
        $this->actingAs($user);
    }

    public function test_index_recherche_types_defauts_erreurs(): void
    {
        $this->superAdmin();

        $contenu = $this->get(route('admin.parametres.index'))->assertOk()->getContent();

        // Recherche + champs typés + défauts affichés.
        $this->assertStringContainsString('role="search"', $contenu);
        $this->assertStringContainsString('recherche-parametres', $contenu);
        $this->assertStringContainsString('type="number"', $contenu);
        $this->assertStringContainsString('Défaut :', $contenu);
        $this->assertStringContainsString('Université', $contenu);

        // Erreur de validation visible sur l'index après soumission invalide
        // (redirection explicite, sac d'erreurs du formulaire).
        $fuseau = Parametre::where('cle', 'etablissement.fuseau')->first();
        $this->patch(route('admin.parametres.update', $fuseau), ['valeur' => 'Nulle part'])
            ->assertRedirect(route('admin.parametres.index'))
            ->assertSessionHasErrors('valeur', null, 'p'.$fuseau->id);
        $this->followingRedirects()->patch(route('admin.parametres.update', $fuseau), ['valeur' => 'Nulle part'])
            ->assertOk()->assertSee('role="alert"', false);
    }

    public function test_auteur_et_date_affiches_apres_modification(): void
    {
        $this->superAdmin();

        $this->patch(
            route('admin.parametres.update', Parametre::where('cle', 'etablissement.nom')->first()),
            ['valeur' => 'Université Bench']
        )->assertRedirect();

        $contenu = $this->get(route('admin.parametres.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Modifié par Super', $contenu);
    }
}
