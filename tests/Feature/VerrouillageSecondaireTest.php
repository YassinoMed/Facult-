<?php

namespace Tests\Feature;

use App\Models\Faculte;
use App\Models\User;
use App\Models\VerrouillagePin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * LOOP N — Verrouillage secondaire : le serveur impose, le client affiche.
 */
class VerrouillageSecondaireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolation du limiteur et du cache entre tests (store `array`
        // partagé par le processus — sinon les 5/min se cumuleraient).
        Cache::flush();
    }

    private function compte(string $role = 'admin_scolarite'): User
    {
        $user = User::creerCompteBackOffice([
            'name' => 'Verrou', 'email' => uniqid('vr.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], $role);
        $this->actingAs($user);

        return $user;
    }

    private function definirPin(?User $compte = null, string $code = '123456'): void
    {
        $this->post(route('admin.verrouillage.definir'), [
            'password' => 'Motdepasse@123',
            'code' => $code,
            'code_confirmation' => $code,
        ])->assertRedirect();
    }

    private function verrouillerSession(): void
    {
        session()->put('derniere_activite', now()->subMinutes(60)->timestamp);
        session()->put('secondaire_verrouille', true);
    }

    public function test_definition_exige_mot_de_passe_et_confirmation(): void
    {
        $compte = $this->compte();

        $this->post(route('admin.verrouillage.definir'), [
            'password' => 'FauxMdp123', 'code' => '123456', 'code_confirmation' => '123456',
        ])->assertSessionHasErrors('password');
        $this->post(route('admin.verrouillage.definir'), [
            'password' => 'Motdepasse@123', 'code' => '123456', 'code_confirmation' => '654321',
        ])->assertSessionHasErrors('code');
        $this->assertSame(0, VerrouillagePin::where('user_id', $compte->id)->count());

        $this->definirPin($compte);
        $this->assertSame(1, VerrouillagePin::where('user_id', $compte->id)->count());
        // Hash, jamais en clair.
        $this->assertStringNotContainsString(
            '123456',
            (string) VerrouillagePin::where('user_id', $compte->id)->value('pin_hash')
        );
    }

    public function test_sans_pin_jamais_de_verrouillage(): void
    {
        $this->compte();
        session()->put('derniere_activite', now()->subMinutes(60)->timestamp);

        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_inactivite_verrouille_get_et_post(): void
    {
        $this->compte();
        $this->definirPin();
        $this->verrouillerSession();

        $reponse = $this->get(route('admin.dashboard'))->assertOk();
        $reponse->assertViewIs('verrouillage.ecran');
        $reponse->assertSee('Session verrouillée', false);

        // POST non exempté vers une route du groupe verrouillé → 423
        // (avant toute validation métier).
        $this->post(route('admin.favoris.basculer'))->assertStatus(423);
        $this->postJson(route('admin.favoris.basculer'))->assertStatus(423);
    }

    public function test_deverrouillage_correct_reprend_sans_reconnexion(): void
    {
        $this->compte();
        $this->definirPin();
        $this->verrouillerSession();

        $this->post(route('admin.verrouillage.verifier'), ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->assertAuthenticated();

        $this->post(route('admin.verrouillage.verifier'), ['code' => '123456'])
            ->assertRedirect();
        $this->assertAuthenticated();
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('Tableau de bord');
    }

    public function test_seuil_et_throttle_anti_brute_force(): void
    {
        $this->compte();
        $this->definirPin();
        $this->verrouillerSession();

        // 4 échecs : erreurs génériques, compteur armé, pas encore bloqué.
        for ($i = 0; $i < 4; $i++) {
            $this->post(route('admin.verrouillage.verifier'), ['code' => '000000'])
                ->assertSessionHasErrors('code');
        }

        $pin = VerrouillagePin::firstWhere('user_id', auth()->id());
        $this->assertSame(4, $pin->echecs);
        $this->assertNull($pin->bloque_until);

        // 5e tentative dans la minute : throttle 5/min (mesuré : 4
        // passages puis 429) — seconde barrière, avant même la temporisation.
        $this->post(route('admin.verrouillage.verifier'), ['code' => '000000'])
            ->assertStatus(429);
    }

    public function test_brute_force_temporise(): void
    {
        $this->compte();
        $this->definirPin();
        $this->verrouillerSession();

        // 2 échecs : erreurs génériques, compteur armé, pas encore bloqué.
        for ($i = 0; $i < 2; $i++) {
            $this->post(route('admin.verrouillage.verifier'), ['code' => '000000'])
                ->assertSessionHasErrors('code');
        }

        $pin = VerrouillagePin::firstWhere('user_id', auth()->id());
        $this->assertSame(2, $pin->echecs);
        $this->assertNull($pin->bloque_until);

        // Seuil atteint en base : temporisation enclenchée, même le bon
        // code est refusé avec le délai (reste sous le throttle 5/min).
        $pin->update(['echecs' => 5, 'bloque_until' => now()->addHour()]);
        $this->post(route('admin.verrouillage.verifier'), ['code' => '123456'])
            ->assertSessionHasErrors('code');

        // Throttle route 5/min : seconde barrière (preuve par configuration).
        $route = collect(Route::getRoutes())
            ->first(fn ($r) => $r->getName() === 'admin.verrouillage.verifier');
        $this->assertContains('throttle:5,1', $route->gatherMiddleware());
    }

    public function test_session_expiree_pin_inutile_et_deconnexion(): void
    {
        $this->compte();
        $this->definirPin();
        $this->verrouillerSession();

        // Déconnexion volontaire depuis l'écran : session détruite.
        $this->post(route('admin.verrouillage.deconnecter'))->assertRedirect();
        $this->assertGuest();

        // Sans session : défi redirigé vers la connexion, jamais déverrouillé.
        $this->get(route('admin.connexion.defi'))->assertRedirect();
        $this->post(route('admin.connexion.defi.verifier'), ['code' => '123456'])
            ->assertRedirect(route('admin.connexion.show'));
    }

    public function test_verrouillage_manuel_et_hooks_securite(): void
    {
        $superAdmin = User::creerCompteBackOffice([
            'name' => 'Super', 'email' => uniqid('sv.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin');
        $compte = $this->compte();
        $this->actingAs($compte);
        $this->definirPin();

        // Verrouillage manuel immédiat.
        $this->post(route('admin.verrouillage.verrouiller'))->assertRedirect();
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('Session verrouillée', false);

        // Suspension : PIN purgé.
        $this->actingAs($superAdmin);
        $this->patch(route('admin.administrateurs.statut', $compte))->assertRedirect();
        $this->assertSame(0, VerrouillagePin::where('user_id', $compte->id)->count());

        // Réinitialisation : PIN purgé.
        $this->actingAs($compte);
        $this->definirPin($compte);
        $this->actingAs($superAdmin);
        $this->post(route('admin.administrateurs.reinitialiser', $compte), [
            'password' => 'NouveauMdp123', 'password_confirmation' => 'NouveauMdp123',
        ])->assertRedirect();
        $this->assertSame(0, VerrouillagePin::where('user_id', $compte->id)->count());
    }

    public function test_overlay_present_si_eligible_seulement(): void
    {
        $this->compte();
        $contenu = $this->get(route('admin.dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('surveillanceInactivite', $contenu);

        $this->definirPin();
        $contenu = $this->get(route('admin.dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('surveillanceInactivite', $contenu);
    }

    public function test_trois_profils_verrouilles_sans_privilege_supplementaire(): void
    {
        foreach (['super_admin', 'admin_faculte', 'agent_scolarite'] as $role) {
            $faculte = Faculte::create([
                'nom' => 'F', 'code' => 'F'.uniqid(), 'description' => 'T', 'active' => true,
            ]);
            $compte = User::creerCompteBackOffice([
                'name' => 'P', 'email' => uniqid('pr.', true).'@scolarite.test',
                'password' => 'Motdepasse@123', 'faculte_id' => $role === 'admin_faculte' ? $faculte->id : null,
            ], $role);
            $this->actingAs($compte);
            $this->definirPin();
            $this->verrouillerSession();

            $this->get(route('admin.archives.index'))->assertOk()->assertSee('Session verrouillée', false);
            $this->assertSame($role, $compte->fresh()->role);
        }
    }
}
