<?php

namespace Tests\Feature;

use App\Models\Parametre;
use App\Models\User;
use App\Services\SettingDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP C — Registre de définitions : couverture des clés connues et
 * règles métier (fuseau, bornes), repli par type préservé.
 */
class SettingsDefinitionsLoopCTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): void
    {
        $user = User::creerCompteBackOffice([
            'name' => 'Super', 'email' => uniqid('sc.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin');
        $this->actingAs($user);
    }

    public function test_cles_institutionnelles_couvertes_et_portee_globale(): void
    {
        // Les 6 clés institutionnelles seedées ont une définition ; les
        // clés métier (ex. sauvegarde.plan.*, UI dédiée) restent en
        // repli par type — comportement conservé.
        foreach (['etablissement.nom', 'etablissement.fuseau', 'academique.seuil_validation', 'assiduite.seuil_alerte', 'notifications.purge_jours', 'interface.pagination'] as $cle) {
            $this->assertArrayHasKey($cle, SettingDefinition::catalogue(), "Clé sans définition : {$cle}.");
        }

        foreach (SettingDefinition::catalogue() as $cle => $definition) {
            $this->assertSame('global', $definition['portee'], $cle);
            $this->assertNotEmpty($definition['regles'], $cle);
            $this->assertNotEmpty($definition['proprietaire'], $cle);
        }
    }

    public function test_fuseau_invalide_refuse(): void
    {
        $this->superAdmin();
        $fuseau = Parametre::where('cle', 'etablissement.fuseau')->first();
        $this->assertNotNull($fuseau);

        $fuseauId = $fuseau->id;
        $this->patch(route('admin.parametres.update', $fuseau), ['valeur' => 'Mars/Olympus'])
            ->assertSessionHasErrors('valeur', null, 'p'.$fuseauId);
        $this->patch(route('admin.parametres.update', $fuseau), ['valeur' => 'Africa/Tunis'])
            ->assertSessionHasNoErrors();
    }

    public function test_bornes_metier_refusees(): void
    {
        $this->superAdmin();

        $bornes = [
            'academique.seuil_validation' => '99',
            'assiduite.seuil_alerte' => '-3',
            'interface.pagination' => '3',
        ];

        foreach ($bornes as $cle => $valeur) {
            $cible = Parametre::where('cle', $cle)->first();
            $this->patch(route('admin.parametres.update', $cible), ['valeur' => $valeur])
                ->assertSessionHasErrors('valeur', null, 'p'.$cible->id);
        }
    }

    public function test_cle_hors_registre_repli_par_type(): void
    {
        $this->superAdmin();
        $libre = Parametre::create([
            'cle' => 'test.libre', 'valeur' => 'x', 'type' => 'texte', 'categorie' => 'interface',
        ]);

        $this->assertNull(SettingDefinition::reglesPour('test.libre'));
        $this->patch(route('admin.parametres.update', $libre), ['valeur' => 'nouvelle valeur'])
            ->assertSessionHasNoErrors();
    }
}
