<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * LOOP-SECURITY-REMEDIATION-01 — origine canonique et réinitialisation de
 * mot de passe :
 * - tout `Host` hors liste de confiance est rejeté en 400 (impossible
 *   d'empoisonner les URL absolues générées) ;
 * - le lien de réinitialisation est construit sur une route EXISTANTE
 *   (`admin.mot-de-passe.reinitialiser`) — l'implémentation Laravel par
 *   défaut référençait `password.reset` (route absente → 500) ;
 * - quand `force_canonical_url` est actif (production), les URL absolues
 *   utilisent systématiquement l'origine APP_URL, quel que soit l'hôte
 *   de la requête.
 */
class OrigineEtResetMotDePasseTest extends TestCase
{
    use RefreshDatabase;

    /* ── Hôtes de confiance ─────────────────────────────────────── */

    public function test_hote_forge_rejete_en_400(): void
    {
        $this->get('http://evil.test/')->assertStatus(400);
        $this->get('http://evil.test/admin/connexion')->assertStatus(400);
    }

    public function test_hote_de_boucle_locale_accepte(): void
    {
        $this->get('http://localhost/')->assertStatus(200);
        $this->get('http://127.0.0.1/')->assertStatus(200);
    }

    public function test_wildcard_accepte_les_sous_domaines_mais_pas_l_apex(): void
    {
        config(['security.trusted_hosts' => ['*.universite.tn']]);

        $this->get('https://scolarite.universite.tn/')->assertStatus(200);

        // Apex non listé : refusé (un wildcard n'ouvre que les sous-domaines).
        $this->get('https://universite.tn/')->assertStatus(400);
    }

    public function test_suffixe_trompeur_refuse(): void
    {
        config(['security.trusted_hosts' => ['*.universite.tn']]);

        $this->get('https://universite.tn.evil.com/')->assertStatus(400);
        $this->get('https://xuniversite.tn/')->assertStatus(400);
    }

    public function test_apex_explicitement_liste_accepte(): void
    {
        config(['security.trusted_hosts' => ['universite.tn', '*.universite.tn']]);

        $this->get('https://universite.tn/')->assertStatus(200);
        $this->get('https://www.universite.tn/')->assertStatus(200);
    }

    /* ── Réinitialisation de mot de passe ───────────────────────── */

    public function test_le_lien_de_reinitialisation_pointe_sur_la_route_existante(): void
    {
        Notification::fake();

        $compte = User::creerCompteBackOffice([
            'name' => 'Compte Test',
            'email' => 'compte.test@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->post(route('admin.mot-de-passe.envoyer'), ['email' => $compte->email])
            ->assertRedirect();

        Notification::assertSentTo($compte, ResetPassword::class, function (ResetPassword $notification) use ($compte): bool {
            $url = (string) $notification->toMail($compte)->actionUrl;

            // Route canonique `admin.mot-de-passe.reinitialiser/{token}`
            // (et non `password.reset`, absente → 500).
            return str_contains($url, '/admin/mot-de-passe/reinitialiser/'.$notification->token)
                && str_contains($url, urlencode($compte->email));
        });
    }

    public function test_origine_canonique_forcee_pour_le_lien_de_reinitialisation(): void
    {
        Notification::fake();

        config([
            'app.url' => 'https://inscription.universite.tn',
            'security.force_canonical_url' => true,
        ]);

        $compte = User::creerCompteBackOffice([
            'name' => 'Compte Canonique',
            'email' => 'compte.canonique@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->post('http://localhost/admin/mot-de-passe/lien', ['email' => $compte->email])
            ->assertRedirect();

        Notification::assertSentTo($compte, ResetPassword::class, function (ResetPassword $notification) use ($compte): bool {
            $url = (string) $notification->toMail($compte)->actionUrl;

            return str_starts_with($url, 'https://inscription.universite.tn/admin/mot-de-passe/reinitialiser')
                && ! str_contains($url, 'localhost');
        });
    }
}
