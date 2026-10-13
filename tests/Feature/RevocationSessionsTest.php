<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AUTH-01 (audit auth 12/10 — P2, correctif 13/10) : toute réinitialisation
 * ou suspension révoque les sessions existantes du compte — un cookie volé
 * ne survit PAS à la réaction défensive de la victime. Kill-switch super_admin
 * dédié. Portail étudiant : version de mot de passe (garde EnsureStudent).
 */
class RevocationSessionsTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();

        Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->super = User::creerCompteBackOffice([
            'name' => 'Super', 'email' => 'super.sessions@universite.test', 'password' => 'Motdepasse@123',
        ], 'super_admin');
    }

    private function semerSession(int $userId, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'TestAgent',
            'payload' => base64_encode('test'),
            'last_activity' => time(),
        ]);
    }

    private function administrateur(string $role, string $email): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Admin '.$role, 'email' => $email, 'password' => 'Motdepasse@123',
        ], $role);
    }

    public function test_reset_via_broker_revoque_toutes_les_sessions_du_compte(): void
    {
        $cible = $this->administrateur('admin_scolarite', 'cible.broker@universite.test');
        $this->semerSession($cible->id, 'sess-broker-1');
        $this->semerSession($cible->id, 'sess-broker-2');

        $token = 'tok-'.Str::random(12);
        DB::table('password_reset_tokens')->insert([
            'email' => $cible->email,
            'token' => Hash::make($token),
            'created_at' => now(),
        ]);

        $this->post(route('admin.mot-de-passe.enregistrer'), [
            'token' => $token,
            'email' => $cible->email,
            'password' => 'NouveauMotdepasse@123',
            'password_confirmation' => 'NouveauMotdepasse@123',
        ])->assertRedirect();

        $this->assertDatabaseMissing('sessions', ['user_id' => $cible->id]);
    }

    public function test_reset_par_super_admin_revoque_la_cible_mais_pas_ses_propres_sessions(): void
    {
        $cible = $this->administrateur('agent_scolarite', 'cible.super@universite.test');
        $this->semerSession($cible->id, 'sess-cible');
        $this->semerSession($this->super->id, 'sess-super');

        $this->actingAs($this->super)
            ->post(route('admin.administrateurs.reinitialiser', $cible), [
                'password' => 'NouveauMotdepasse@123',
                'password_confirmation' => 'NouveauMotdepasse@123',
            ])->assertRedirect();

        $this->assertDatabaseMissing('sessions', ['id' => 'sess-cible']);
        $this->assertDatabaseHas('sessions', ['id' => 'sess-super']);
    }

    public function test_suspension_revoque_les_sessions_du_compte(): void
    {
        $cible = $this->administrateur('agent_scolarite', 'cible.statut@universite.test');
        $this->semerSession($cible->id, 'sess-statut');

        $this->actingAs($this->super)
            ->patch(route('admin.administrateurs.statut', $cible))
            ->assertRedirect();

        $this->assertFalse($cible->fresh()->actif);
        $this->assertDatabaseMissing('sessions', ['id' => 'sess-statut']);
    }

    public function test_kill_switch_super_admin_revoque_les_sessions_et_refuse_les_autres(): void
    {
        $cible = $this->administrateur('agent_scolarite', 'cible.kill@universite.test');
        $this->semerSession($cible->id, 'sess-kill');

        // Kill-switch : super_admin uniquement (groupe de routes).
        $this->actingAs($this->super)
            ->post(route('admin.administrateurs.sessions.purger', $cible))
            ->assertRedirect();

        $this->assertDatabaseMissing('sessions', ['id' => 'sess-kill']);

        // Un admin non super_admin est refusé (middleware du groupe).
        $this->semerSession($cible->id, 'sess-kill-2');
        $scolarite = $this->administrateur('admin_scolarite', 'sco.kill@universite.test');

        $this->actingAs($scolarite)
            ->post(route('admin.administrateurs.sessions.purger', $cible))
            ->assertForbidden();

        $this->assertDatabaseHas('sessions', ['id' => 'sess-kill-2']);
    }

    public function test_reset_etudiant_par_scolarite_invalide_sa_session_ouverte(): void
    {
        $etudiant = $this->etudiant();
        $scolarite = $this->administrateur('admin_scolarite', 'sco.etu@universite.test');

        // Session OUVERTE avant le reset : valide (rétrocompat : version
        // absente en session = 0 = colonne par défaut).
        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.profil.show'))
            ->assertOk();

        $this->actingAs($scolarite)
            ->post(route('admin.etudiants.reinitialiserMotDePasse', $etudiant), [
                'confirmation' => '1',
            ])->assertRedirect();

        $this->assertSame(1, (int) $etudiant->fresh()->mot_de_passe_version);

        // La même session (cookie « volé ») est expirée au prochain accès.
        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.profil.show'))
            ->assertRedirect(route('etudiant.connexion.show'));
    }

    public function test_connexion_etudiant_pose_la_version_en_session(): void
    {
        $etudiant = $this->etudiant();
        $etudiant->update(['password' => 'Connue@12345']);
        $etudiant->increment('mot_de_passe_version');

        $this->post(route('etudiant.connexion.login'), [
            'identifiant' => $etudiant->email,
            'password' => 'Connue@12345',
        ])->assertRedirect()->assertSessionHas('etudiant_mdp_version', 1);
    }

    private function etudiant(): Etudiant
    {
        return Etudiant::create([
            'cin' => 'BK123456',
            'nom' => 'Alami',
            'prenom' => 'Mehdi',
            'email' => 'mehdi.alami@example.com',
            'telephone' => '+212600000000',
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
            'faculte_id' => Faculte::firstOrFail()->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => '12 Rue de la Liberté',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'mention_bac' => 'Très Bien',
            'password' => 'Motdepasse@123',
        ]);
    }
}
