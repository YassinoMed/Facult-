<?php

namespace Tests\Feature;

use App\Mail\PortailMotDePasseMail;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\PortalPasswordResetToken;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Réinitialisation de mot de passe des portails :
 * - token hashé, expirant (60 min), à usage unique ;
 * - réponse générique (anti-énumération) et limite de demandes ;
 * - invalidation des sessions existantes via la version de mot de passe.
 */
class PortailMotDePasseTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
    }

    public function test_les_pages_publiques_sont_accessibles(): void
    {
        $this->get(route('etudiant.mot-de-passe.oublie'))->assertOk()->assertSee('Mot de passe oublié');
        $this->get(route('enseignant.mot-de-passe.oublie'))->assertOk()->assertSee('Mot de passe oublié');
        $this->get(route('etudiant.mot-de-passe.reinitialiser', ['token' => 'abc', 'email' => 'x@y.test']))
            ->assertOk()
            ->assertSee('Nouveau mot de passe');
    }

    public function test_demande_avec_email_inconnu_reste_generique_et_n_envoie_rien(): void
    {
        Mail::fake();

        $this->post(route('etudiant.mot-de-passe.envoyer'), ['email' => 'inconnu@example.com'])
            ->assertRedirect(route('etudiant.mot-de-passe.oublie'))
            ->assertSessionHas('success');

        Mail::assertNothingSent();
        $this->assertDatabaseCount('portal_password_reset_tokens', 0);
    }

    public function test_etudiant_recoit_un_lien_et_peut_reinitialiser_une_seule_fois(): void
    {
        Mail::fake();
        $etudiant = $this->creerEtudiant('etudiant.reset@example.com');
        $versionInitiale = (int) $etudiant->mot_de_passe_version;

        $this->post(route('etudiant.mot-de-passe.envoyer'), ['email' => 'etudiant.reset@example.com'])
            ->assertRedirect(route('etudiant.mot-de-passe.oublie'));

        $url = null;
        Mail::assertSent(PortailMotDePasseMail::class, function (PortailMotDePasseMail $mail) use (&$url): bool {
            $url = $mail->url;

            return $mail->hasTo('etudiant.reset@example.com');
        });

        $this->assertNotNull($url);
        $token = $this->tokenDepuisUrl($url);
        $this->assertSame(64, strlen($token));

        // Le token n'est jamais stocké en clair.
        $this->assertDatabaseMissing('portal_password_reset_tokens', ['token_hash' => $token]);
        $this->assertDatabaseHas('portal_password_reset_tokens', [
            'guard' => 'etudiant', 'email' => 'etudiant.reset@example.com', 'used_at' => null,
        ]);

        $this->post(route('etudiant.mot-de-passe.enregistrer'), [
            'token' => $token,
            'email' => 'etudiant.reset@example.com',
            'password' => 'NouveauMotDePasse123',
            'password_confirmation' => 'NouveauMotDePasse123',
        ])->assertRedirect(route('etudiant.connexion.show'));

        $etudiant->refresh();
        $this->assertTrue(Hash::check('NouveauMotDePasse123', $etudiant->password));
        $this->assertSame($versionInitiale + 1, (int) $etudiant->mot_de_passe_version);

        // Rejeu : refusé, mot de passe inchangé.
        $this->post(route('etudiant.mot-de-passe.enregistrer'), [
            'token' => $token,
            'email' => 'etudiant.reset@example.com',
            'password' => 'EncoreUnAutre123',
            'password_confirmation' => 'EncoreUnAutre123',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('NouveauMotDePasse123', $etudiant->fresh()->password));
    }

    public function test_token_expire_est_refuse(): void
    {
        $etudiant = $this->creerEtudiant('expire@example.com');
        $token = 'token-expire-'.str_repeat('x', 50);

        PortalPasswordResetToken::create([
            'guard' => 'etudiant',
            'email' => 'expire@example.com',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->subMinute(),
            'used_at' => null,
        ]);

        $this->post(route('etudiant.mot-de-passe.enregistrer'), [
            'token' => $token,
            'email' => 'expire@example.com',
            'password' => 'NouveauMotDePasse123',
            'password_confirmation' => 'NouveauMotDePasse123',
        ])->assertSessionHasErrors('email');

        $this->assertFalse(Hash::check('NouveauMotDePasse123', $etudiant->fresh()->password));
    }

    public function test_une_session_ouverte_est_invalidee_apres_reinitialisation(): void
    {
        Mail::fake();
        $etudiant = $this->creerEtudiant('session.reset@example.com');
        $versionInitiale = (int) $etudiant->mot_de_passe_version;

        $this->post(route('etudiant.mot-de-passe.envoyer'), ['email' => 'session.reset@example.com']);
        $url = null;
        Mail::assertSent(PortailMotDePasseMail::class, function (PortailMotDePasseMail $mail) use (&$url): bool {
            $url = $mail->url;

            return true;
        });

        $this->post(route('etudiant.mot-de-passe.enregistrer'), [
            'token' => $this->tokenDepuisUrl((string) $url),
            'email' => 'session.reset@example.com',
            'password' => 'NouveauMotDePasse123',
            'password_confirmation' => 'NouveauMotDePasse123',
        ]);

        // Ancienne session (ancienne version) : redirigée vers la connexion.
        $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => $versionInitiale,
        ])->get(route('etudiant.dashboard'))
            ->assertRedirect(route('etudiant.connexion.show'));
    }

    public function test_enseignant_peut_reinitialiser_et_version_incremente(): void
    {
        Mail::fake();
        $enseignant = $this->creerEnseignant('enseignant.reset@example.com');
        $versionInitiale = (int) $enseignant->mot_de_passe_version;

        $this->post(route('enseignant.mot-de-passe.envoyer'), ['email' => 'enseignant.reset@example.com']);
        $url = null;
        Mail::assertSent(PortailMotDePasseMail::class, function (PortailMotDePasseMail $mail) use (&$url): bool {
            $url = $mail->url;

            return $mail->hasTo('enseignant.reset@example.com');
        });

        $this->post(route('enseignant.mot-de-passe.enregistrer'), [
            'token' => $this->tokenDepuisUrl((string) $url),
            'email' => 'enseignant.reset@example.com',
            'password' => 'NouveauMotDePasse123',
            'password_confirmation' => 'NouveauMotDePasse123',
        ])->assertRedirect(route('enseignant.connexion.show'));

        $enseignant->refresh();
        $this->assertTrue(Hash::check('NouveauMotDePasse123', $enseignant->password));
        $this->assertSame($versionInitiale + 1, (int) $enseignant->mot_de_passe_version);
    }

    public function test_enseignant_inactif_ne_recoit_aucun_lien(): void
    {
        Mail::fake();
        $this->creerEnseignant('inactif@example.com', ['actif' => false]);

        $this->post(route('enseignant.mot-de-passe.envoyer'), ['email' => 'inactif@example.com'])
            ->assertRedirect(route('enseignant.mot-de-passe.oublie'))
            ->assertSessionHas('success');

        Mail::assertNothingSent();
        $this->assertDatabaseCount('portal_password_reset_tokens', 0);
    }

    public function test_demandes_repetees_sont_limitees(): void
    {
        Mail::fake();
        $this->creerEtudiant('limite@example.com');

        foreach (range(1, 4) as $_) {
            $this->post(route('etudiant.mot-de-passe.envoyer'), ['email' => 'limite@example.com']);
        }

        $this->assertSame(3, Mail::sent(PortailMotDePasseMail::class)->count());
    }

    private function creerEtudiant(string $email): Etudiant
    {
        return Etudiant::create([
            'cin' => 'CIN'.substr(md5($email), 0, 7),
            'nom' => 'BEN ALI',
            'prenom' => 'Mohamed',
            'email' => $email,
            'telephone' => '+212612345678',
            'date_naissance' => '2003-05-17',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L2',
            'annee_academique' => '2025-2026',
            'adresse' => '45 Avenue Hassan II',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2022',
            'mention_bac' => 'Bien',
            'password' => 'MotdepasseSecret123',
        ]);
    }

    private function creerEnseignant(string $email, array $surcharge = []): Teacher
    {
        $departement = Departement::create([
            'code' => 'DEP-'.uniqid(), 'nom' => 'Département', 'faculte_id' => $this->faculte->id,
        ]);

        return Teacher::create(array_merge([
            'nom' => 'Nom', 'prenom' => 'Prenom', 'email' => $email,
            'matricule' => 'ENS-'.uniqid(), 'departement_id' => $departement->id,
            'actif' => true, 'statut' => 'actif', 'password' => 'Motdepasse@123',
        ], $surcharge));
    }

    private function tokenDepuisUrl(string $url): string
    {
        // Le token est un segment de chemin (/reinitialiser/{token}) ;
        // l'e-mail est en query string.
        $chemin = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $segments = explode('/', $chemin);

        return (string) end($segments);
    }
}
