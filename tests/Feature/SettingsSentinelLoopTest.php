<?php

namespace Tests\Feature;

use App\Models\Parametre;
use App\Models\User;
use App\Services\ParametreService;
use App\Services\SettingDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Point 3 — Clés `sentinel.*` : seedées aux défauts codés, pilotables UI,
 * couvertes par le registre.
 */
class SettingsSentinelLoopTest extends TestCase
{
    use RefreshDatabase;

    public function test_sentinel_seede_registre_et_pilotable(): void
    {
        foreach (['sentinel.file_echecs_seuil' => 5, 'sentinel.file_attente_seuil' => 1000, 'sentinel.notif_cooldown_minutes' => 60] as $cle => $defaut) {
            $this->assertSame($defaut, app(ParametreService::class)->get($cle));
            $this->assertNotNull(SettingDefinition::reglesPour($cle), $cle);
        }

        $user = User::creerCompteBackOffice([
            'name' => 'Super', 'email' => uniqid('ss.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin');
        $this->actingAs($user);

        $cible = Parametre::where('cle', 'sentinel.notif_cooldown_minutes')->first();
        $this->patch(route('admin.parametres.update', $cible), ['valeur' => '30', 'attendu' => $cible->updated_at?->toIso8601String()])
            ->assertRedirect();
        $this->assertSame(30, app(ParametreService::class)->get('sentinel.notif_cooldown_minutes'));
        $this->patch(route('admin.parametres.update', $cible->fresh()), ['valeur' => '0', 'attendu' => $cible->fresh()->updated_at?->toIso8601String()])
            ->assertSessionHasErrors('valeur', null, 'p'.$cible->id);
    }
}
