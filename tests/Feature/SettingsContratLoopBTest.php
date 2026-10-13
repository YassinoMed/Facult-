<?php

namespace Tests\Feature;

use App\Models\Parametre;
use App\Models\User;
use App\Services\ParametreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * LOOP B — Contrats du noyau Settings existant : matrice d'accès,
 * typage, clés inconnues, cache, journal sans secret.
 */
class SettingsContratLoopBTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $user = User::creerCompteBackOffice([
            'name' => 'Super', 'email' => uniqid('sb.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin');
        $this->actingAs($user);

        return $user;
    }

    private function restreint(string $role = 'admin_faculte'): User
    {
        $user = User::creerCompteBackOffice([
            'name' => 'Restreint', 'email' => uniqid('rb.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], $role);
        $this->actingAs($user);

        return $user;
    }

    public function test_matrice_acces_lecture_ecriture(): void
    {
        Parametre::create(['cle' => 'test.matrice', 'valeur' => '1', 'type' => 'entier', 'categorie' => 'interface']);
        $cible = Parametre::where('cle', 'test.matrice')->first();

        // Restreint : ni lecture ni écriture.
        $this->restreint();
        $this->get(route('admin.parametres.index'))->assertForbidden();
        $this->patch(route('admin.parametres.update', $cible), ['valeur' => '2'])->assertForbidden();
        $this->assertSame('1', Parametre::where('cle', 'test.matrice')->value('valeur'));

        // Super-admin : lecture + écriture.
        $this->superAdmin();
        $this->get(route('admin.parametres.index'))->assertOk();
        $this->patch(route('admin.parametres.update', $cible), ['valeur' => '2', 'attendu' => $cible->updated_at?->toIso8601String()])->assertRedirect();
        $this->assertSame(2, app(ParametreService::class)->get('test.matrice'));
    }

    public function test_cle_inconnue_404_et_type_invalide_422(): void
    {
        $this->superAdmin();
        Parametre::create(['cle' => 'test.typé', 'valeur' => '1', 'type' => 'entier', 'categorie' => 'interface']);
        $cible = Parametre::where('cle', 'test.typé')->first();

        $this->patch(route('admin.parametres.update', $cible), ['valeur' => 'pas-un-entier', 'attendu' => $cible->updated_at?->toIso8601String()])
            ->assertSessionHasErrors('valeur', null, 'p'.$cible->id);
        $this->patch('/admin/parametres/999999', ['valeur' => 'x'])->assertNotFound();
    }

    public function test_cache_invalide_apres_ecriture(): void
    {
        $this->superAdmin();
        Parametre::create(['cle' => 'test.cache', 'valeur' => 'a', 'type' => 'texte', 'categorie' => 'interface']);

        $this->assertSame('a', app(ParametreService::class)->get('test.cache'));
        $enCache = Cache::get('parametre.test.cache');
        $this->assertIsArray($enCache);
        $this->assertSame('a', $enCache['valeur']);

        $cache = Parametre::where('cle', 'test.cache')->first();
        $this->patch(route('admin.parametres.update', $cache), ['valeur' => 'b', 'attendu' => $cache->updated_at?->toIso8601String()])
            ->assertRedirect();
        $this->assertSame('b', app(ParametreService::class)->get('test.cache'));
    }

    public function test_entree_cache_corrompue_auto_guerie(): void
    {
        $this->superAdmin();
        Parametre::create(['cle' => 'test.poison', 'valeur' => 'saine', 'type' => 'texte', 'categorie' => 'interface']);

        // Entrée historique au mauvais format (ex. modèle sérialisé
        // illisible) : lecture purgée puis servie depuis la base, sans 500.
        Cache::forever('parametre.test.poison', new \stdClass);
        $this->assertSame('saine', app(ParametreService::class)->get('test.poison'));
        $this->assertSame('saine', app(ParametreService::class)->get('test.poison'));
    }

    public function test_journal_sans_secret_et_valeur_par_defaut(): void
    {
        $captures = [];
        Log::listen(function (MessageLogged $evenement) use (&$captures) {
            if (($evenement->message ?? '') === 'parametre.modifie') {
                $captures[] = $evenement->context;
            }
        });
        $this->superAdmin();
        Parametre::create(['cle' => 'test.journal', 'valeur' => '1', 'type' => 'entier', 'categorie' => 'interface']);

        // Absent = défaut, jamais d'exception.
        $this->assertSame('defaut', app(ParametreService::class)->get('test.absente', 'defaut'));

        $journal = Parametre::where('cle', 'test.journal')->first();
        $this->patch(route('admin.parametres.update', $journal), ['valeur' => '2', 'attendu' => $journal->updated_at?->toIso8601String()])
            ->assertRedirect();
        $this->assertCount(1, $captures);
        $this->assertSame('test.journal', $captures[0]['cle'] ?? null);
        $this->assertSame('1', $captures[0]['ancien'] ?? null);
        $this->assertSame('2', $captures[0]['nouveau'] ?? null);
    }
}
