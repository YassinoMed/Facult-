<?php

namespace Tests\Feature;

use App\Mail\CourrielMessage;
use App\Models\Courriel;
use App\Models\User;
use App\Services\CourrielService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * LOOP-MAIL-ENTERPRISE-01 — module de messagerie (LOOP 08) : catalogue,
 * validation, journalisation expurgée, idempotence, reprise encadrée,
 * cockpit et permissions. Transport `array` en test : aucun envoi réel.
 */
class CourrielModuleTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $utilisateur = User::creerCompteBackOffice([
            'name' => 'Super', 'email' => uniqid('cm.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin');

        $this->actingAs($utilisateur);

        return $utilisateur;
    }

    private function agentSansPermission(): void
    {
        $utilisateur = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => uniqid('cmagent.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->actingAs($utilisateur);
    }

    private function service(): CourrielService
    {
        return app(CourrielService::class);
    }

    public function test_catalogue_rejette_modele_inconnu_et_variables_interdites(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->envoyer('modele_inexistant', 'test@example.com', []);
    }

    public function test_validation_rejette_destinataire_invalide_et_injection(): void
    {
        foreach (['pas-un-email', "a@example.com\r\nBcc: x@y.zz", str_repeat('a', 250).'@example.com'] as $destinataire) {
            try {
                $this->service()->envoyer('message_manuel', $destinataire, ['message' => 'Bonjour']);
                $this->fail('Destinataire accepté : '.$destinataire);
            } catch (InvalidArgumentException) {
            }
        }

        try {
            $this->service()->envoyer('message_manuel', 'test@example.com', ['code' => '123456']);
            $this->fail('Variable interdite acceptée.');
        } catch (InvalidArgumentException) {
        }

        try {
            $this->service()->envoyer('message_manuel', 'test@example.com', ['message' => str_repeat('x', 501)]);
            $this->fail('Message trop long accepté.');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame(0, Courriel::count());
    }

    public function test_modeles_otp_reserves_aux_parcours_securite(): void
    {
        foreach (['code_verification', 'code_changement_email', 'code_nouvelle_adresse'] as $modele) {
            try {
                $this->service()->envoyer($modele, 'test@example.com', ['code' => '123456', 'minutes_validite' => 10]);
                $this->fail('Modèle OTP émissible : '.$modele);
            } catch (InvalidArgumentException) {
            }
        }

        $this->assertSame(0, Courriel::count());
    }

    public function test_envoi_journalise_sans_corps_ni_adresse(): void
    {
        $courriel = $this->service()->envoyer('message_manuel', 'Etudiant.Test@Example.com', [
            'message' => 'Votre dossier avance bien.',
        ], ['cle' => 'test-1', 'acteur_admin_id' => 7]);

        $courriel->refresh();
        $this->assertSame(Courriel::STATUT_ACCEPTE_SMTP, $courriel->statut);
        $this->assertSame(1, $courriel->tentatives);
        $this->assertNotNull($courriel->envoye_le);
        $this->assertSame(7, $courriel->acteur_admin_id);
        $this->assertSame('etu******@example.com', $courriel->destinataire_masque);
        $this->assertSame(hash('sha256', 'etudiant.test@example.com'), $courriel->destinataire_hash);
        $this->assertArrayNotHasKey('message', $courriel->donnees ?? []);
        $this->assertStringNotContainsString('etudiant.test@example.com', (string) json_encode($courriel->toArray()));
        $this->assertStringNotContainsString('Votre dossier avance bien', (string) json_encode($courriel->toArray()));
    }

    public function test_idempotence_meme_cle_un_seul_envoi(): void
    {
        $envois = [];
        Mail::fake();
        // Avec `Mail::fake`, aucun transport : seule la déduplication est prouvée ici.
        $premier = $this->service()->envoyer('message_manuel', 'idem@example.com', ['message' => 'Bonjour'], ['cle' => 'cle-fixe']);
        $second = $this->service()->envoyer('message_manuel', 'idem@example.com', ['message' => 'Bonjour'], ['cle' => 'cle-fixe']);

        $this->assertSame($premier->id, $second->id);
        $this->assertSame(1, Courriel::where('cle_idempotence', 'cle-fixe')->count());
        Mail::assertSent(CourrielMessage::class, 1);
    }

    public function test_journal_passif_sans_entete_masque_et_marche(): void
    {
        Mail::to('passif@example.com')->send(new CourrielMessage(0, 'hors_catalogue', 'Sujet test', ['Ligne']));

        $ligne = Courriel::where('categorie', 'passif')->firstOrFail();
        $this->assertSame(Courriel::STATUT_ACCEPTE_SMTP, $ligne->statut);
        $this->assertSame('pas******@example.com', $ligne->destinataire_masque);
        $this->assertStringNotContainsString('passif@example.com', (string) json_encode($ligne->toArray()));
    }

    public function test_previsualiser_n_ecrit_ni_n_envoie_rien(): void
    {
        $apercu = $this->service()->previsualiser('message_manuel', ['message' => 'Bonjour']);
        $this->assertSame('Message du service de la scolarité', $apercu['sujet']);
        $this->assertContains('Bonjour', $apercu['lignes']);
        $this->assertSame(0, Courriel::count());
    }

    public function test_reprise_refusee_otp_autre_adresse_et_plafond(): void
    {
        $otp = Courriel::create([
            'categorie' => 'securite', 'modele' => 'code_verification', 'version_modele' => '1',
            'statut' => Courriel::STATUT_ECHEC, 'tentatives' => 1,
            'destinataire_masque' => 'tes******@example.com',
            'destinataire_hash' => Courriel::condensatDestinataire('test@example.com'),
            'sujet' => 'Code', 'donnees' => ['reemissible' => false],
        ]);

        try {
            $this->service()->reessayer($otp, 'test@example.com');
            $this->fail('Reprise OTP acceptée.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('parcours', $e->getMessage());
        }

        $reemissible = $this->service()->envoyer('confirmation_mise_a_jour', 'reprise@example.com', [
            'reference' => 'INS-1', 'nb_champs_modifies' => 2,
        ], ['cle' => 'reprise-1']);
        $reemissible->update(['statut' => Courriel::STATUT_ECHEC]);
        // Anti-boucle neutralisé pour le chemin nominal (test dédié ci-dessous).
        Courriel::whereKey($reemissible->id)->update(['updated_at' => now()->subMinutes(5)]);

        try {
            $this->service()->reessayer($reemissible, 'autre@example.com');
            $this->fail('Reprise vers une autre adresse acceptée.');
        } catch (DomainException) {
        }

        $relance = $this->service()->reessayer($reemissible->fresh(), 'reprise@example.com');
        $this->assertSame(Courriel::STATUT_ACCEPTE_SMTP, $relance->statut);
        $this->assertSame(1, $relance->donnees['reessais_manuels']);

        // Cooldown anti-boucle : seconde reprise immédiate refusée.
        try {
            $relance->update(['statut' => Courriel::STATUT_ECHEC]);
            $this->service()->reessayer($relance->fresh(), 'reprise@example.com');
            $this->fail('Reprise immédiate acceptée.');
        } catch (DomainException) {
        }
    }

    public function test_cockpit_reserve_aux_permissions(): void
    {
        $this->get(route('admin.courriels.index'))->assertRedirect(route('admin.connexion.show'));

        $this->agentSansPermission();
        $this->get(route('admin.courriels.index'))->assertForbidden();
        $this->get(route('admin.courriels.catalogue'))->assertForbidden();
        $this->get(route('admin.courriels.creer'))->assertForbidden();
        $this->post(route('admin.courriels.envoyer'), [])->assertForbidden();
    }

    public function test_cockpit_super_admin_et_envoi_manuel_en_deux_temps(): void
    {
        $this->superAdmin();

        $this->get(route('admin.courriels.index'))->assertOk();
        $this->get(route('admin.courriels.catalogue'))->assertOk();
        $this->get(route('admin.courriels.creer'))->assertOk();

        // Étape aperçu : aucune écriture, aucun envoi.
        $this->post(route('admin.courriels.envoyer'), [
            'destinataire' => 'manuel@example.com',
            'message' => 'Bonjour, ceci est un test.',
            'etape' => 'apercu',
        ])->assertOk()->assertSee('Aperçu avant envoi réel', false);
        $this->assertSame(0, Courriel::count());

        // Confirmation sans case cochée : retour à l'aperçu, toujours rien.
        $this->post(route('admin.courriels.envoyer'), [
            'destinataire' => 'manuel@example.com',
            'message' => 'Bonjour, ceci est un test.',
            'etape' => 'confirmer',
        ])->assertOk();
        $this->assertSame(0, Courriel::count());

        // Confirmation explicite : envoi réel (transport `array`), acteur audité.
        $this->post(route('admin.courriels.envoyer'), [
            'destinataire' => 'manuel@example.com',
            'message' => 'Bonjour, ceci est un test.',
            'etape' => 'confirmer',
            'confirmation' => '1',
        ])->assertRedirect();
        $ligne = Courriel::sole();
        $this->assertSame(Courriel::STATUT_ACCEPTE_SMTP, $ligne->statut);
        $this->assertNotNull($ligne->acteur_admin_id);

        // Destinataire invalide : erreur, aucune écriture.
        $this->post(route('admin.courriels.envoyer'), [
            'destinataire' => 'invalide',
            'message' => 'Bonjour.',
            'etape' => 'apercu',
        ])->assertRedirect(route('admin.courriels.creer'));
        $this->assertSame(1, Courriel::count());
    }

    public function test_vues_n_exposent_ni_secret_ni_adresse(): void
    {
        $this->superAdmin();

        $contenu = $this->get(route('admin.courriels.index'))->getContent();
        $this->assertStringNotContainsString('mot_de_passe', $contenu);

        $this->get(route('admin.courriels.creer'))
            ->assertOk()
            ->assertDontSee('manuel@example.com');
    }

    public function test_classification_erreurs_sans_secret(): void
    {
        $service = $this->service();
        $this->assertSame('authentification', $service->classifierErreur(new \RuntimeException('535-5.7.8 Username and Password not accepted')));
        $this->assertSame('destinataire', $service->classifierErreur(new \RuntimeException('550 5.1.1 mailbox unavailable')));
        $this->assertSame('connexion', $service->classifierErreur(new \RuntimeException('Connection timed out')));
        $this->assertSame('inconnue', $service->classifierErreur(new \RuntimeException('Erreur inattendue')));

        $expurge = CourrielService::expurgerMessage(new \RuntimeException('Échec vers cible@example.com : 550'));
        $this->assertStringNotContainsString('cible@example.com', $expurge);
        $this->assertStringContainsString('[adresse]', $expurge);
    }
}
