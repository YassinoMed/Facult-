<?php

namespace Tests\Feature;

use App\Models\Faculte;
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
        $this->patch(route('admin.parametres.update', $fuseau), ['valeur' => 'Nulle part', 'attendu' => $fuseau->updated_at?->toIso8601String()])
            ->assertRedirect(route('admin.parametres.index'))
            ->assertSessionHasErrors('valeur', null, 'p'.$fuseau->id);
        $this->followingRedirects()->patch(route('admin.parametres.update', $fuseau->fresh()), ['valeur' => 'Nulle part', 'attendu' => $fuseau->fresh()->updated_at?->toIso8601String()])
            ->assertOk()->assertSee('role="alert"', false);
    }

    public function test_auteur_et_date_affiches_apres_modification(): void
    {
        $this->superAdmin();

        $cibleNom = Parametre::where('cle', 'etablissement.nom')->first();
        $this->patch(
            route('admin.parametres.update', $cibleNom),
            ['valeur' => 'Université Bench', 'attendu' => $cibleNom->updated_at?->toIso8601String()]
        )->assertRedirect();

        $contenu = $this->get(route('admin.parametres.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Modifié par Super', $contenu);
    }

    public function test_etat_personnalise_confirmation_et_diagnostic(): void
    {
        $this->superAdmin();

        // Valeur modifiée (≠ défaut 180) → badge + confirmation fort impact.
        $purge = Parametre::where('cle', 'notifications.purge_jours')->first();
        $this->patch(
            route('admin.parametres.update', $purge),
            ['valeur' => '30', 'attendu' => $purge->updated_at?->toIso8601String()]
        )->assertRedirect();

        $contenu = $this->get(route('admin.parametres.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Personnalisé', $contenu);
        $this->assertStringContainsString('confirmer la modification', $contenu);
        $this->assertStringContainsString('État de configuration', $contenu);
        $this->assertStringContainsString('Débogage', $contenu);
        $this->assertStringContainsString('Aperçu', $contenu);
        $this->assertStringContainsString('Enregistrement', $contenu);
        $this->assertStringNotContainsString('APP_KEY', $contenu);
    }

    public function test_sidebar_parametres_visible_super_admin_seul(): void
    {
        $this->superAdmin();
        $this->get(route('admin.archives.index'))->assertOk()->assertSee('Paramètres', false);

        $faculte = Faculte::create(['nom' => 'FA', 'code' => 'FA'.uniqid(), 'description' => 'T', 'active' => true]);
        $restreint = User::creerCompteBackOffice([
            'name' => 'Restreint', 'email' => uniqid('sb.', true).'@scolarite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $faculte->id,
        ], 'admin_faculte');
        $this->actingAs($restreint);
        $this->get(route('admin.archives.index'))->assertOk()->assertDontSee('Paramètres', false);
    }
}
