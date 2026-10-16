<?php

namespace Tests\Feature;

use App\Http\Middleware\SecondaireVerrou;
use App\Models\Faculte;
use App\Models\User;
use App\Models\VerrouillagePin;
use App\Modules\Sentinel\Services\Checks\VerrouillageSecuriteCheck;
use App\Services\MfaTotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    public function test_page_code_affiche_etat_enregistrement(): void
    {
        $compte = $this->compte();

        // Sans PIN : avertissement explicite, verrouillage inactif.
        $this->get(route('admin.verrouillage.code'))->assertOk()
            ->assertSee('Aucun code enregistré', false);

        $this->definirPin($compte);

        // Avec PIN : état confirmé + délai rappelé.
        $this->get(route('admin.verrouillage.code'))->assertOk()
            ->assertSee('Un code est enregistré', false)
            ->assertSee('inactivité', false);
    }

    public function test_erreurs_pin_en_francais_jamais_de_cle_brute(): void
    {
        $this->compte();

        // Code trop court : message français, jamais « validation.digits ».
        $reponse = $this->post(route('admin.verrouillage.definir'), [
            'password' => 'Motdepasse@123',
            'code' => '1234',
            'code_confirmation' => '1234',
        ]);
        $reponse->assertSessionHasErrors('code');
        $erreurs = session('errors')->get('code');
        $this->assertNotEmpty($erreurs);
        foreach ($erreurs as $message) {
            $this->assertStringNotContainsString('validation.', $message);
        }
        $this->assertStringContainsString('6 chiffres', $erreurs[0]);
    }

    public function test_inactivite_verrouille_get_et_post(): void
    {
        $this->compte();
        $this->definirPin();
        $this->verrouillerSession();

        $reponse = $this->get(route('admin.dashboard'))->assertOk();
        $reponse->assertViewIs('verrouillage.ecran');
        $reponse->assertSee('Session verrouillée', false);
        // B6 : champ masqué + bascule ; consigne de récupération.
        $reponse->assertSee('type="password"', false);
        $reponse->assertSee('verrou-afficher', false);

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

        // 5 échecs : erreurs génériques ; le 5e arme la temporisation
        // (pas encore le throttle route, budget isolé `verrou-verifier`).
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('admin.verrouillage.verifier'), ['code' => '000000'])
                ->assertSessionHasErrors('code');
        }

        $pin = VerrouillagePin::firstWhere('user_id', auth()->id());
        $this->assertSame(5, $pin->echecs);

        // 6e tentative dans la minute : throttle 5/min (compartiment
        // dédié) — seconde barrière, avant même la temporisation.
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

        // Throttle route 5/min dédié (compartiment `verrou-verifier`,
        // LOOP P : le budget anti-brute-force n'est plus consommé par
        // les autres routes throttlées du même compte) : seconde barrière.
        $route = collect(Route::getRoutes())
            ->first(fn ($r) => $r->getName() === 'admin.verrouillage.verifier');
        $this->assertContains('throttle:5,1,verrou-verifier', $route->gatherMiddleware());
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
        // B7 : la page code est joignable depuis le menu compte.
        $this->assertStringContainsString('Code de verrouillage', $contenu);
    }

    public function test_roles_back_office_verrouilles_sans_privilege_supplementaire(): void
    {
        foreach (['super_admin', 'admin_scolarite', 'agent_scolarite', 'consultation', 'admin_faculte'] as $role) {
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

            // GET verrouillé → écran, sans privilège : le verrou précède
            // toute autorisation métier.
            $this->get(route('admin.dashboard'))->assertOk()
                ->assertViewIs('verrouillage.ecran')
                ->assertSee('Session verrouillée', false)
                ->assertSee('Code oublié ?', false);

            // POST non exempté → 423 avant toute validation métier.
            $this->post(route('admin.favoris.basculer'))->assertStatus(423);
            $this->postJson(route('admin.favoris.basculer'))->assertStatus(423);

            $this->assertSame($role, $compte->fresh()->role);
        }
    }

    public function test_routes_modules_couvertes_par_le_verrou(): void
    {
        $this->compte('super_admin');
        $this->definirPin();

        // Sans verrou : page module servie (le voile client, présent en
        // DOM masqué, contient lui aussi ce libellé : on tranche par la vue).
        $this->get(route('admin.sentinel.index'))->assertOk()
            ->assertViewIs('modules.sentinel.index');

        // Verrouillé : écran serveur, y compris sur les routes modules.
        $this->verrouillerSession();
        $this->get(route('admin.sentinel.index'))->assertOk()
            ->assertViewIs('verrouillage.ecran')
            ->assertSee('Session verrouillée', false);
    }

    public function test_sonde_passive_impose_verrou_sans_toucher(): void
    {
        $this->compte();
        $this->definirPin();

        Route::middleware(['web', 'auth', 'secondaire.verrou:passif'])
            ->get('/__sonde-lock', fn () => response()->json(['ok' => true]));

        // Session saine mais non touchée depuis 120 s : 200 et
        // `derniere_activite` inchangée (pas de keep-alive par sondage).
        session()->put('derniere_activite', now()->subSeconds(120)->timestamp);
        $avant = session('derniere_activite');
        $this->getJson('/__sonde-lock')->assertOk()->assertJson(['ok' => true]);
        $this->assertSame($avant, session('derniere_activite'));

        // Session expirée : 423 verrouillé, sans toucher non plus.
        $this->verrouillerSession();
        $marqueur = session('derniere_activite');
        $this->getJson('/__sonde-lock')->assertStatus(423)
            ->assertJson(['message' => 'Session verrouillée après inactivité.']);
        $this->assertSame($marqueur, session('derniere_activite'));
    }

    public function test_revocation_session_verrouillee_exige_mot_de_passe(): void
    {
        $compte = $this->compte();
        $this->definirPin();
        $this->verrouillerSession();

        DB::table('sessions')->insert([
            'id' => 'sess-distante', 'user_id' => $compte->id, 'ip_address' => '1.2.3.4',
            'user_agent' => 'Test', 'payload' => '', 'last_activity' => time(),
        ]);

        // Sans mot de passe : refusé, session conservée.
        $this->delete(route('admin.sessions.destroy', 'sess-distante'))
            ->assertSessionHasErrors('password');
        $this->assertDatabaseHas('sessions', ['id' => 'sess-distante']);

        // Avec mot de passe : révoquée même sous verrou (voie de secours).
        $this->delete(route('admin.sessions.destroy', 'sess-distante'), ['password' => 'Motdepasse@123'])
            ->assertSessionHas('success');
        $this->assertDatabaseMissing('sessions', ['id' => 'sess-distante']);
    }

    public function test_sentinel_signale_verrou_desactive_et_pins_bloques(): void
    {
        $check = new VerrouillageSecuriteCheck;
        $this->assertSame('SECURITE_VERROUILLAGE', $check->code());
        $this->assertSame('securite', $check->categorie());

        // Configuration saine : aucun constat.
        $this->assertSame([], $check->controler());

        // Verrou globalement désactivé : constat, sans aucune donnée personnelle.
        DB::table('parametres')->where('cle', 'verrouillage.actif')->update(['valeur' => '0']);
        Cache::flush();
        $constats = $check->controler();
        $this->assertNotEmpty($constats);
        $this->assertStringContainsString('désactivé', $constats[0]['titre']);
        $brut = json_encode($constats);
        $this->assertIsString($brut);
        $this->assertStringNotContainsString('@', $brut);

        // Code temporairement bloqué : constat informatif (comptes uniquement).
        DB::table('parametres')->where('cle', 'verrouillage.actif')->update(['valeur' => '1']);
        Cache::flush();
        $compte = $this->compte();
        $this->definirPin($compte);
        VerrouillagePin::where('user_id', $compte->id)->update(['bloque_until' => now()->addHour()]);
        $constats = $check->controler();
        $this->assertCount(1, $constats);
        $this->assertStringContainsString('bloqués', $constats[0]['titre']);
    }

    public function test_livewire_update_bloque_sous_verrou(): void
    {
        // P0 LOOP P : une session verrouillée ne doit jamais agir via un
        // composant Livewire — la requête est rejetée 423 AVANT tout
        // traitement (le middleware doit voir la session authentifiée,
        // donc s'exécuter APRÈS le groupe `web`).
        $this->compte();
        $this->definirPin();

        $charge = ['fingerprint' => [], 'serverMemo' => [], 'updates' => []];
        $entetes = ['X-Livewire' => 'true'];

        // Session saine : la requête atteint Livewire (jamais 423) —
        // le verrou ne doit pas casser l'usage légitime du composant.
        $saine = $this->postJson('/livewire/update', $charge, $entetes);
        $this->assertNotEquals(423, $saine->getStatusCode());

        $this->verrouillerSession();

        $this->postJson('/livewire/update', $charge, $entetes)->assertStatus(423);
    }

    public function test_throttles_verrouillage_isoles_par_fonction(): void
    {
        // LOOP P §2.2 : les budgets de limitation sont compartimentés par
        // fonction — l'usage légitime des autres routes throttlées ne doit
        // pas épuiser le budget anti-brute-force du vérificateur.
        $this->compte();
        $this->definirPin();
        $this->verrouillerSession();

        // 3 définitions (compartiment `verrou-definir`, budget 10/min)...
        for ($i = 0; $i < 3; $i++) {
            $this->post(route('admin.verrouillage.definir'), [
                'password' => 'Motdepasse@123',
                'code' => '123456',
                'code_confirmation' => '123456',
            ])->assertRedirect();
        }

        // ...puis 5 vérifications fautives : le budget `verrou-verifier`
        // (5/min) est intact — aucune 429 prématurée, la 6e est bloquée.
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('admin.verrouillage.verifier'), ['code' => '000000'])
                ->assertSessionHasErrors('code');
        }
        $this->post(route('admin.verrouillage.verifier'), ['code' => '000000'])
            ->assertStatus(429);
    }

    public function test_recuperation_sous_verrou_exige_mot_de_passe(): void
    {
        // LOOP P §2.2/2.3 : les routes de réglage restent joignables sous
        // verrou (porte de secours « code oublié ») MAIS exigent toujours
        // le mot de passe (+ TOTP si MFA) — jamais un contournement.
        $this->compte();
        $this->definirPin();
        $this->verrouillerSession();

        // PIN faux : erreur générique, session TOUJOURS verrouillée.
        $this->post(route('admin.verrouillage.verifier'), ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->get(route('admin.dashboard'))->assertViewIs('verrouillage.ecran');

        // Redéfinition avec un faux mot de passe : refusée, l'ancien
        // code reste le seul valide.
        $this->post(route('admin.verrouillage.definir'), [
            'password' => 'FauxMdp1234',
            'code' => '654321',
            'code_confirmation' => '654321',
        ])->assertSessionHasErrors('password');
        $this->post(route('admin.verrouillage.verifier'), ['code' => '123456'])
            ->assertRedirect();
        $this->get(route('admin.dashboard'))->assertOk();

        // Redéfinition légitime (mot de passe actuel) : le nouveau code
        // déverrouille, l'ancien ne fonctionne plus.
        $this->post(route('admin.verrouillage.verrouiller'))->assertRedirect();
        $redef = $this->post(route('admin.verrouillage.definir'), [
            'password' => 'Motdepasse@123',
            'code' => '654321',
            'code_confirmation' => '654321',
        ]);
        $redef->assertRedirect();
        $this->post(route('admin.verrouillage.verifier'), ['code' => '123456'])
            ->assertSessionHasErrors('code');
        $this->post(route('admin.verrouillage.verifier'), ['code' => '654321'])
            ->assertRedirect();
        $this->get(route('admin.dashboard'))->assertOk();

        // Session expirée : le défi ne déverrouille jamais, redirection
        // vers la connexion (aucune action non autorisée possible).
        $this->post(route('admin.connexion.logout'))->assertRedirect();
        $this->assertGuest();
        $this->post(route('admin.verrouillage.verifier'), ['code' => '654321'])
            ->assertRedirect(route('admin.connexion.show'));
    }

    public function test_livewire_update_traverse_le_verrou(): void
    {
        // B2 : l'endpoint Livewire porte le middleware (pas de
        // contournement par composant sous verrou).
        $route = collect(Route::getRoutes())
            ->first(fn ($r) => $r->getName() === 'livewire.update');
        $this->assertNotNull($route);
        $middlewares = collect($route->gatherMiddleware())
            ->map(fn ($m) => is_string($m) ? $m : (is_object($m) ? $m::class : ''))
            ->all();
        $this->assertTrue(
            in_array('secondaire.verrou', $middlewares, true)
            || in_array(SecondaireVerrou::class, $middlewares, true),
            'livewire.update doit traverser SecondaireVerrou.'
        );
    }

    public function test_definition_pin_compte_mfa_exige_totp(): void
    {
        // B3 : un compte avec double facteur ne peut définir son PIN
        // qu'avec un TOTP valide (le formulaire affiche le champ).
        $compte = $this->compte();
        $secret = app(MfaTotpService::class)->genererSecret();
        $compte->forceFill(['mfa_secret' => $secret, 'mfa_active' => true])->save();

        $this->get(route('admin.verrouillage.code'))->assertOk()
            ->assertSee('Double facteur (6 chiffres)', false);

        // Sans TOTP : refusé, aucun PIN créé.
        $this->post(route('admin.verrouillage.definir'), [
            'password' => 'Motdepasse@123',
            'code' => '123456',
            'code_confirmation' => '123456',
        ])->assertSessionHasErrors('totp');
        $this->assertSame(0, VerrouillagePin::where('user_id', $compte->id)->count());

        // TOTP erroné : refusé.
        $this->post(route('admin.verrouillage.definir'), [
            'password' => 'Motdepasse@123',
            'code' => '123456',
            'code_confirmation' => '123456',
            'totp' => '000000',
        ])->assertSessionHasErrors('totp');
        $this->assertSame(0, VerrouillagePin::where('user_id', $compte->id)->count());

        // TOTP valide : PIN créé.
        $code = app(MfaTotpService::class)->calculer($secret, (int) floor(time() / 30));
        $this->post(route('admin.verrouillage.definir'), [
            'password' => 'Motdepasse@123',
            'code' => '123456',
            'code_confirmation' => '123456',
            'totp' => $code,
        ])->assertRedirect();
        $this->assertSame(1, VerrouillagePin::where('user_id', $compte->id)->count());
    }
}
