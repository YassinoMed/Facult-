<?php

namespace Tests\Feature\Security;

use App\Mail\PortailMotDePasseMail;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\PortalPasswordResetToken;
use App\Models\User;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * LOOP-01 — Authentification, sessions et récupération de compte :
 * 1. Connexion valide et invalide.
 * 2. Tentatives répétées de connexion (rate limiting).
 * 3. Régénération de session après authentification (anti-fixation).
 * 4. Invalidation de session après changement de mot de passe (multi-session & admin reset).
 * 5. Jeton de récupération expiré, invalide et réutilisé.
 * 6. Déconnexion puis tentative de réutilisation de session.
 * 7. Accès aux pages protégées sans authentification.
 */
class Loop01AuthentificationSessionsTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FST',
            'description' => 'Sciences',
            'active' => true,
        ]);

        $this->etudiant = Etudiant::create([
            'cin' => 'BK123456',
            'nom' => 'Alami',
            'prenom' => 'Mehdi',
            'email' => 'mehdi.alami@example.com',
            'telephone' => '+212600000000',
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => '12 Rue de la Liberté',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'password' => 'Motdepasse@123',
        ]);
        $this->etudiant->forceFill(['num_inscription' => 'INS-2026-00001'])->save();
        $this->etudiant->definirStatut('valide');
    }

    /* ── 1. Connexion valide et invalide ────────────────────────────── */

    public function test_01_connexion_valide_avec_email_ou_cin_normalise(): void
    {
        // Connexion avec email (majuscules/minuscules indifférentes)
        $reponseEmail = $this->post(route('etudiant.connexion.login'), [
            'identifiant' => 'MEHDI.ALAMI@EXAMPLE.COM',
            'password' => 'Motdepasse@123',
        ]);
        $reponseEmail->assertRedirect(route('etudiant.profil.show'));
        $this->assertSame($this->etudiant->id, session('etudiant_auth_id'));

        // Déconnexion propre
        $this->post(route('etudiant.connexion.logout'));

        // Connexion avec CIN normalisé (minuscules converties en majuscules)
        $reponseCin = $this->post(route('etudiant.connexion.login'), [
            'identifiant' => 'bk123456',
            'password' => 'Motdepasse@123',
        ]);
        $reponseCin->assertRedirect(route('etudiant.profil.show'));
        $this->assertSame($this->etudiant->id, session('etudiant_auth_id'));
    }

    public function test_01_connexion_invalide_retourne_message_generique_anti_enumeration(): void
    {
        // Cas A : mot de passe erroné
        $reponseMdpFaux = $this->post(route('etudiant.connexion.login'), [
            'identifiant' => 'mehdi.alami@example.com',
            'password' => 'MauvaisMotDePasse',
        ]);
        $reponseMdpFaux->assertSessionHasErrors(['identifiant']);
        $this->assertNull(session('etudiant_auth_id'));

        // Cas B : identifiant inconnu
        $reponseInconnu = $this->post(route('etudiant.connexion.login'), [
            'identifiant' => 'inconnu@example.com',
            'password' => 'Motdepasse@123',
        ]);
        $reponseInconnu->assertSessionHasErrors(['identifiant']);
        $this->assertNull(session('etudiant_auth_id'));

        // Les deux messages d'erreur sont strictement identiques
        $msgA = session('errors')->getBag('default')->first('identifiant');
        $this->assertStringContainsString('Identifiants incorrects', $msgA);
    }

    public function test_01_connexion_echoue_si_etudiant_sans_mot_de_passe(): void
    {
        $sansMdp = Etudiant::create([
            'cin' => 'BK999999',
            'nom' => 'Sans',
            'prenom' => 'Pass',
            'email' => 'sanspass@example.com',
            'date_naissance' => '2004-01-01',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Rue',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2024',
            'password' => null,
        ]);
        $sansMdp->forceFill(['password' => null])->save();

        $reponse = $this->post(route('etudiant.connexion.login'), [
            'identifiant' => 'sanspass@example.com',
            'password' => 'NimporteQuelMdp',
        ]);

        $reponse->assertSessionHasErrors(['identifiant']);
        $this->assertNull(session('etudiant_auth_id'));
    }

    /* ── 2. Tentatives répétées de connexion (Rate Limiting) ────────── */

    public function test_02_tentatives_repetees_de_connexion_declenchent_rate_limiting(): void
    {
        // 5 tentatives autorisées par minute (throttle:5,1)
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('etudiant.connexion.login'), [
                'identifiant' => 'mehdi.alami@example.com',
                'password' => 'FauxPass'.$i,
            ]);
        }

        // 6ème tentative rejetée en 429 Too Many Requests
        $reponse = $this->post(route('etudiant.connexion.login'), [
            'identifiant' => 'mehdi.alami@example.com',
            'password' => 'FauxPass6',
        ]);

        $reponse->assertStatus(429);
    }

    /* ── 3. Régénération de session après authentification ──────────── */

    public function test_03_regeneration_de_session_apres_authentification(): void
    {
        $this->get(route('etudiant.connexion.show'));
        $sessionIdInitial = session()->getId();

        $this->post(route('etudiant.connexion.login'), [
            'identifiant' => 'mehdi.alami@example.com',
            'password' => 'Motdepasse@123',
        ]);

        $sessionIdApresLogin = session()->getId();

        $this->assertNotEmpty($sessionIdInitial);
        $this->assertNotEmpty($sessionIdApresLogin);
        $this->assertNotSame($sessionIdInitial, $sessionIdApresLogin, 'L\'ID de session doit être régénéré à la connexion (anti-fixation).');
    }

    /* ── 4. Invalidation de session après changement de mot de passe ── */

    public function test_04_invalidation_sessions_concurrentes_apres_changement_mot_de_passe_par_etudiant(): void
    {
        // Connexion Session A
        $this->post(route('etudiant.connexion.login'), [
            'identifiant' => 'mehdi.alami@example.com',
            'password' => 'Motdepasse@123',
        ]);
        $versionInitiale = (int) $this->etudiant->fresh()->mot_de_passe_version;

        // Session A modifie son mot de passe
        $reponseModif = $this->patch(route('etudiant.profil.password'), [
            'current_password' => 'Motdepasse@123',
            'password' => 'NouveauMotDePasse@456',
            'password_confirmation' => 'NouveauMotDePasse@456',
        ]);
        $reponseModif->assertRedirect(route('etudiant.profil.show'));

        $versionNouvelle = (int) $this->etudiant->fresh()->mot_de_passe_version;
        $this->assertSame($versionInitiale + 1, $versionNouvelle);

        // Session A reste valide car elle détient la nouvelle version
        $this->get(route('etudiant.dashboard'))->assertOk();

        // Session B concurrente (qui détenait encore l'ancienne version) tente un accès
        $reponseSessionB = $this->withSession([
            'etudiant_auth_id' => $this->etudiant->id,
            'etudiant_mdp_version' => $versionInitiale,
        ])->get(route('etudiant.dashboard'));

        // Session B est immédiatement invalidée par EnsureStudent et redirigée vers la connexion
        $reponseSessionB->assertRedirect(route('etudiant.connexion.show'))
            ->assertSessionHas('error');
    }

    public function test_04_invalidation_session_apres_reinitialisation_administrative(): void
    {
        $admin = User::creerCompteBackOffice([
            'name' => 'Scolarite',
            'email' => 'scolarite@universite.test',
            'password' => 'AdminPass@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_scolarite');

        // Session étudiante active
        $versionInitiale = (int) $this->etudiant->mot_de_passe_version;

        // Scolarité réinitialise le mot de passe
        $this->actingAs($admin)->post(route('admin.etudiants.reinitialiserMotDePasse', $this->etudiant), [
            'confirmation' => '1',
        ])->assertRedirect();

        $this->assertSame($versionInitiale + 1, (int) $this->etudiant->fresh()->mot_de_passe_version);

        // L'étudiant qui avait sa session ouverte tente une navigation
        $this->withSession([
            'etudiant_auth_id' => $this->etudiant->id,
            'etudiant_mdp_version' => $versionInitiale,
        ])->get(route('etudiant.dashboard'))
            ->assertRedirect(route('etudiant.connexion.show'));
    }

    /* ── 5. Jeton de récupération expiré, invalide et réutilisé ─────── */

    public function test_05_demande_recuperation_avec_email_inconnu_ne_revele_rien(): void
    {
        Mail::fake();

        $reponse = $this->post(route('etudiant.mot-de-passe.envoyer'), [
            'email' => 'inconnu@example.com',
        ]);

        $reponse->assertRedirect(route('etudiant.mot-de-passe.oublie'))
            ->assertSessionHas('success');

        Mail::assertNothingSent();
        $this->assertDatabaseCount('portal_password_reset_tokens', 0);
    }

    public function test_05_jeton_recuperation_valide_puis_reutilise_est_refuse(): void
    {
        Mail::fake();

        $this->post(route('etudiant.mot-de-passe.envoyer'), [
            'email' => 'mehdi.alami@example.com',
        ]);

        $url = null;
        Mail::assertSent(PortailMotDePasseMail::class, function (PortailMotDePasseMail $mail) use (&$url): bool {
            $url = $mail->url;

            return true;
        });

        $this->assertNotNull($url);
        $token = $this->extraireToken($url);

        // Utilisation valide
        $reponse = $this->post(route('etudiant.mot-de-passe.enregistrer'), [
            'token' => $token,
            'email' => 'mehdi.alami@example.com',
            'password' => 'ToutNouveauMdp@999',
            'password_confirmation' => 'ToutNouveauMdp@999',
        ]);

        $reponse->assertRedirect(route('etudiant.connexion.show'))
            ->assertSessionHas('success');

        $this->assertTrue(Hash::check('ToutNouveauMdp@999', $this->etudiant->fresh()->password));

        // Rejeu immédiat avec le même token : refusé
        $rejeu = $this->post(route('etudiant.mot-de-passe.enregistrer'), [
            'token' => $token,
            'email' => 'mehdi.alami@example.com',
            'password' => 'TentativeRejeu@111',
            'password_confirmation' => 'TentativeRejeu@111',
        ]);

        $rejeu->assertSessionHasErrors(['email']);
        $this->assertFalse(Hash::check('TentativeRejeu@111', $this->etudiant->fresh()->password));
    }

    public function test_05_jeton_recuperation_invalide_est_refuse(): void
    {
        $reponse = $this->post(route('etudiant.mot-de-passe.enregistrer'), [
            'token' => 'token-totalement-invalide-et-inexistant-12345678901234567890',
            'email' => 'mehdi.alami@example.com',
            'password' => 'NouveauMdp@123',
            'password_confirmation' => 'NouveauMdp@123',
        ]);

        $reponse->assertSessionHasErrors(['email']);
        $this->assertTrue(Hash::check('Motdepasse@123', $this->etudiant->fresh()->password));
    }

    public function test_05_jeton_recuperation_expire_est_refuse(): void
    {
        $token = str_repeat('e', 64);
        PortalPasswordResetToken::create([
            'guard' => 'etudiant',
            'email' => 'mehdi.alami@example.com',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->subMinute(),
            'used_at' => null,
        ]);

        $reponse = $this->post(route('etudiant.mot-de-passe.enregistrer'), [
            'token' => $token,
            'email' => 'mehdi.alami@example.com',
            'password' => 'NouveauMdp@123',
            'password_confirmation' => 'NouveauMdp@123',
        ]);

        $reponse->assertSessionHasErrors(['email']);
        $this->assertTrue(Hash::check('Motdepasse@123', $this->etudiant->fresh()->password));
    }

    public function test_05_jeton_emis_pour_un_email_ne_peut_pas_etre_utilise_pour_un_autre(): void
    {
        Mail::fake();

        $autre = Etudiant::create([
            'cin' => 'BK654321',
            'nom' => 'Autre',
            'prenom' => 'Etudiante',
            'email' => 'autre.etudiante@example.com',
            'date_naissance' => '2004-06-20',
            'sexe' => 'F',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L2',
            'annee_academique' => '2025-2026',
            'adresse' => '8 Rue du Test',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2023',
            'password' => 'AutreMotdepasse@123',
        ]);

        $this->post(route('etudiant.mot-de-passe.envoyer'), [
            'email' => $this->etudiant->email,
        ]);

        $url = null;
        Mail::assertSent(PortailMotDePasseMail::class, function (PortailMotDePasseMail $mail) use (&$url): bool {
            $url = $mail->url;

            return true;
        });

        $reponse = $this->post(route('etudiant.mot-de-passe.enregistrer'), [
            'token' => $this->extraireToken((string) $url),
            'email' => $autre->email,
            'password' => 'Detournement@123',
            'password_confirmation' => 'Detournement@123',
        ]);

        $reponse->assertSessionHasErrors(['email']);
        $this->assertTrue(Hash::check('Motdepasse@123', $this->etudiant->fresh()->password));
        $this->assertTrue(Hash::check('AutreMotdepasse@123', $autre->fresh()->password));
    }

    /* ── 6. Déconnexion puis tentative de réutilisation de session ──── */

    public function test_06_deconnexion_invalide_la_session(): void
    {
        $this->post(route('etudiant.connexion.login'), [
            'identifiant' => 'mehdi.alami@example.com',
            'password' => 'Motdepasse@123',
        ]);
        $this->assertSame($this->etudiant->id, session('etudiant_auth_id'));

        $sessionIdAvant = session()->getId();
        $jetonCsrfAvant = session()->token();

        // Déconnexion
        $reponseLogout = $this->post(route('etudiant.connexion.logout'));
        $reponseLogout->assertRedirect(route('etudiant.connexion.show'));

        $this->assertNull(session('etudiant_auth_id'));
        $this->assertNotSame($sessionIdAvant, session()->getId(), 'La déconnexion doit régénérer l\'ID de session.');
        $this->assertNotSame($jetonCsrfAvant, session()->token(), 'La déconnexion doit régénérer le jeton CSRF.');

        // Tentative d'accès immédiat après déconnexion
        $this->get(route('etudiant.dashboard'))
            ->assertRedirect(route('etudiant.connexion.show'));
    }

    /* ── 7. Accès aux pages protégées sans authentification ─────────── */

    public function test_07_pages_protegees_inaccessibles_sans_authentification(): void
    {
        $routesProtegees = [
            route('etudiant.dashboard'),
            route('etudiant.profil.show'),
            route('etudiant.profil.edit'),
            route('etudiant.inscriptions.index'),
            route('etudiant.notes.index'),
            route('etudiant.documents.index'),
            route('etudiant.parcours'),
            route('etudiant.examens.index'),
            route('etudiant.rendez-vous.nouveau'),
            route('etudiant.notifications.index'),
        ];

        foreach ($routesProtegees as $url) {
            $this->get($url)->assertRedirect(route('etudiant.connexion.show'));
        }
    }

    /* ── 8. Le jeton émis AVANT un changement de mot de passe ────────── */

    /**
     * Un lien de récupération émis avant un changement de mot de passe ne
     * doit plus être consommable : sinon, un ancien e-mail (boîte
     * compromise, lien réutilisé) permettrait de reprendre le compte APRÈS
     * que la victime a réagi.
     */
    public function test_08_jeton_emis_avant_changement_de_mot_de_passe_par_le_profil_est_refuse(): void
    {
        Mail::fake();

        $this->post(route('etudiant.mot-de-passe.envoyer'), [
            'email' => $this->etudiant->email,
        ]);

        $url = null;
        Mail::assertSent(PortailMotDePasseMail::class, function (PortailMotDePasseMail $mail) use (&$url): bool {
            $url = $mail->url;

            return true;
        });
        $token = $this->extraireToken((string) $url);

        $this->post(route('etudiant.connexion.login'), [
            'identifiant' => $this->etudiant->email,
            'password' => 'Motdepasse@123',
        ])->assertRedirect(route('etudiant.profil.show'));

        $this->patch(route('etudiant.profil.password'), [
            'current_password' => 'Motdepasse@123',
            'password' => 'ProfilChange@456',
            'password_confirmation' => 'ProfilChange@456',
        ])->assertRedirect(route('etudiant.profil.show'));

        $reponse = $this->post(route('etudiant.mot-de-passe.enregistrer'), [
            'token' => $token,
            'email' => $this->etudiant->email,
            'password' => 'ResetTardif@789',
            'password_confirmation' => 'ResetTardif@789',
        ]);

        $reponse->assertSessionHasErrors(['email']);
        $this->assertTrue(Hash::check('ProfilChange@456', $this->etudiant->fresh()->password));

        $jeton = PortalPasswordResetToken::where('guard', 'etudiant')
            ->where('email', $this->etudiant->email)
            ->first();

        $this->assertNotNull($jeton);
        $this->assertNotNull($jeton->used_at, 'Le jeton actif doit être neutralisé dès le changement de mot de passe.');
    }

    public function test_08_jeton_emis_avant_reinitialisation_administrative_est_refuse(): void
    {
        Mail::fake();

        $this->post(route('etudiant.mot-de-passe.envoyer'), [
            'email' => $this->etudiant->email,
        ]);

        $url = null;
        Mail::assertSent(PortailMotDePasseMail::class, function (PortailMotDePasseMail $mail) use (&$url): bool {
            $url = $mail->url;

            return true;
        });
        $token = $this->extraireToken((string) $url);

        $admin = User::creerCompteBackOffice([
            'name' => 'Scolarite',
            'email' => 'scolarite.reset@universite.test',
            'password' => 'AdminPass@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_scolarite');

        $this->actingAs($admin)->post(route('admin.etudiants.reinitialiserMotDePasse', $this->etudiant), [
            'confirmation' => '1',
        ])->assertRedirect();

        $reponse = $this->post(route('etudiant.mot-de-passe.enregistrer'), [
            'token' => $token,
            'email' => $this->etudiant->email,
            'password' => 'ResetTardif@789',
            'password_confirmation' => 'ResetTardif@789',
        ]);

        $reponse->assertSessionHasErrors(['email']);
        $this->assertFalse(Hash::check('ResetTardif@789', $this->etudiant->fresh()->password));

        $jeton = PortalPasswordResetToken::where('guard', 'etudiant')
            ->where('email', $this->etudiant->email)
            ->first();

        $this->assertNotNull($jeton);
        $this->assertNotNull($jeton->used_at, 'Le jeton actif doit être neutralisé dès le reset administratif.');
    }

    public function test_08_jeton_enseignant_emis_avant_changement_de_mot_de_passe_est_refuse(): void
    {
        Mail::fake();

        $departement = Departement::create([
            'code' => 'DEP-LOOP01',
            'nom' => 'Département LOOP-01',
            'faculte_id' => $this->faculte->id,
        ]);

        $enseignant = Teacher::create([
            'nom' => 'Ben Salah',
            'prenom' => 'Karim',
            'email' => 'karim.ben.salah@universite.test',
            'matricule' => 'ENS-LOOP01',
            'departement_id' => $departement->id,
            'actif' => true,
            'statut' => 'actif',
            'password' => 'Enseignant@123',
        ]);

        $this->post(route('enseignant.mot-de-passe.envoyer'), [
            'email' => $enseignant->email,
        ]);

        $url = null;
        Mail::assertSent(PortailMotDePasseMail::class, function (PortailMotDePasseMail $mail) use (&$url): bool {
            $url = $mail->url;

            return true;
        });
        $token = $this->extraireToken((string) $url);

        $this->withSession([
            'enseignant_auth_id' => $enseignant->id,
            'enseignant_mdp_version' => 0,
        ])->patch(route('enseignant.profil.password'), [
            'current_password' => 'Enseignant@123',
            'password' => 'EnseignantChange@456',
            'password_confirmation' => 'EnseignantChange@456',
        ])->assertRedirect(route('enseignant.profil.show'));

        $reponse = $this->post(route('enseignant.mot-de-passe.enregistrer'), [
            'token' => $token,
            'email' => $enseignant->email,
            'password' => 'ResetTardif@789',
            'password_confirmation' => 'ResetTardif@789',
        ]);

        $reponse->assertSessionHasErrors(['email']);
        $this->assertTrue(Hash::check('EnseignantChange@456', $enseignant->fresh()->password));

        $jeton = PortalPasswordResetToken::where('guard', 'enseignant')
            ->where('email', $enseignant->email)
            ->first();

        $this->assertNotNull($jeton);
        $this->assertNotNull($jeton->used_at, 'Le jeton actif doit être neutralisé dès le changement de mot de passe.');
    }

    /* ── 9. Durcissement des pages qui portent le jeton dans l'URL ──── */

    public function test_09_pages_de_reinitialisation_interdisent_referer_et_cache(): void
    {
        foreach (['etudiant', 'enseignant'] as $prefixe) {
            $reponse = $this->get(route($prefixe.'.mot-de-passe.reinitialiser', [
                'token' => str_repeat('a', 64),
                'email' => 'personne@example.com',
            ]));

            $reponse->assertOk();
            $this->assertSame(
                'no-referrer',
                $reponse->headers->get('Referrer-Policy'),
                "La page {$prefixe} ne doit jamais transmettre le jeton via Referer.",
            );
            $this->assertStringContainsString(
                'no-store',
                (string) $reponse->headers->get('Cache-Control'),
                "La page {$prefixe} ne doit pas être mise en cache.",
            );
        }
    }

    private function extraireToken(string $url): string
    {
        $chemin = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $parties = explode('/', $chemin);

        return (string) end($parties);
    }
}
