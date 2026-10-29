<?php

namespace Tests\Feature;

use App\Models\Faculte;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LOOP-ROLE-ASSIGNMENT-UX-01 : attribution des rôles via menu rapide et
 * page des rôles — même mécanisme métier, confirmation, audit, gardes.
 */
class AttributionRoleTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Faculte $faculte;

    private string $fichierAudit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FSTA', 'description' => 'T', 'active' => true]);
        $this->superAdmin = User::creerCompteBackOffice([
            'name' => 'Super', 'email' => 'super.attrib@universite.test', 'password' => 'Motdepasse@123',
        ], 'super_admin');

        // Canal d'audit isolé pour assertions de contenu (fichier temporaire).
        $this->fichierAudit = storage_path('logs/audit-attribution-test.log');
        @unlink($this->fichierAudit);
        config()->set('logging.channels.audit_test', [
            'driver' => 'single', 'path' => $this->fichierAudit, 'level' => 'debug',
        ]);
        config()->set('logging.default', 'audit_test');
    }

    private function agent(string $role = 'agent_scolarite'): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Cible', 'email' => uniqid('ci.', true).'@universite.test', 'password' => 'Motdepasse@123',
        ], $role);
    }

    private function contenuAudit(): string
    {
        return is_file($this->fichierAudit) ? (string) file_get_contents($this->fichierAudit) : '';
    }

    /* ── Fonctionnel ── */

    public function test_liste_affiche_badge_role_et_bouton_rapide(): void
    {
        $cible = $this->agent();

        $this->actingAs($this->superAdmin)->get(route('admin.administrateurs.index'))
            ->assertOk()
            ->assertSee('id="role-badge-'.$cible->id.'"', false)
            ->assertSee('Rôle', false)
            ->assertSee('attributionRole', false);
    }

    public function test_page_roles_bouton_attribuer_et_effectifs(): void
    {
        $this->actingAs($this->superAdmin)->get(route('admin.roles.index'))
            ->assertOk()
            ->assertSee('Attribuer ce rôle', false)
            ->assertSee('id="effectif-agent_scolarite"', false)
            ->assertSee('Voir les permissions', false);
    }

    public function test_attribution_reussie_menu_comptes_json(): void
    {
        $cible = $this->agent();

        $reponse = $this->actingAs($this->superAdmin)->patchJson(
            route('admin.administrateurs.role.attribuer', $cible),
            ['role' => 'admin_scolarite', 'role_actuel' => 'agent_scolarite']
        )->assertOk();

        $reponse->assertJsonPath('ok', true)
            ->assertJsonPath('ancien_role', 'agent_scolarite')
            ->assertJsonPath('nouveau_role', 'admin_scolarite');

        $this->assertSame('admin_scolarite', $cible->fresh()->role);
    }

    public function test_attribution_reussie_page_roles_json_et_faculte(): void
    {
        $cible = $this->agent();

        $this->actingAs($this->superAdmin)->postJson(route('admin.roles.attribuer'), [
            'user_id' => $cible->id, 'role' => 'admin_faculte',
            'faculte_id' => $this->faculte->id, 'role_actuel' => 'agent_scolarite',
        ])->assertOk()->assertJsonPath('nouveau_role', 'admin_faculte');

        $cible->refresh();
        $this->assertSame('admin_faculte', $cible->role);
        $this->assertSame($this->faculte->id, $cible->faculte_id);
    }

    public function test_sortie_admin_faculte_purge_faculte(): void
    {
        $cible = $this->agent('admin_faculte');
        $cible->update(['faculte_id' => $this->faculte->id]);

        $this->actingAs($this->superAdmin)->patchJson(
            route('admin.administrateurs.role.attribuer', $cible),
            ['role' => 'consultation', 'role_actuel' => 'admin_faculte']
        )->assertOk();

        $cible->refresh();
        $this->assertSame('consultation', $cible->role);
        $this->assertNull($cible->faculte_id);
    }

    public function test_idempotence_meme_role(): void
    {
        $cible = $this->agent();

        $this->actingAs($this->superAdmin)->patchJson(
            route('admin.administrateurs.role.attribuer', $cible),
            ['role' => 'agent_scolarite', 'role_actuel' => 'agent_scolarite']
        )->assertOk()->assertJsonPath('inchange', true);

        $this->assertStringNotContainsString('admin.role_attribue', $this->contenuAudit());
    }

    public function test_erreur_validation_role_inconnu(): void
    {
        $cible = $this->agent();

        $this->actingAs($this->superAdmin)->patchJson(
            route('admin.administrateurs.role.attribuer', $cible),
            ['role' => 'president', 'role_actuel' => 'agent_scolarite']
        )->assertStatus(422);

        $this->assertSame('agent_scolarite', $cible->fresh()->role);
    }

    public function test_admin_faculte_sans_faculte_refuse(): void
    {
        $cible = $this->agent();

        $this->actingAs($this->superAdmin)->patchJson(
            route('admin.administrateurs.role.attribuer', $cible),
            ['role' => 'admin_faculte', 'role_actuel' => 'agent_scolarite']
        )->assertStatus(422);

        $this->assertSame('agent_scolarite', $cible->fresh()->role);
    }

    /* ── Autorisation ── */

    public function test_invite_refuse(): void
    {
        $cible = $this->agent();

        $this->get(route('admin.administrateurs.index'))->assertRedirect();
        $this->patchJson(route('admin.administrateurs.role.attribuer', $cible), ['role' => 'consultation'])->assertUnauthorized();
        $this->postJson(route('admin.roles.attribuer'), ['user_id' => $cible->id, 'role' => 'consultation'])->assertUnauthorized();
    }

    public function test_non_super_admin_refuse_partout(): void
    {
        $acteur = $this->agent('admin_scolarite');
        $cible = $this->agent();
        $this->actingAs($acteur);

        // Groupe super_admin : 403 ; page rôles : 403 (ni view ni manage).
        $this->patchJson(route('admin.administrateurs.role.attribuer', $cible), ['role' => 'consultation'])->assertForbidden();
        $this->postJson(route('admin.roles.attribuer'), ['user_id' => $cible->id, 'role' => 'consultation'])->assertForbidden();
        $this->getJson(route('admin.administrateurs.recherche', ['q' => 'Ci']))->assertForbidden();

        $this->assertSame('agent_scolarite', $cible->fresh()->role);
    }

    public function test_auto_attribution_interdite(): void
    {
        $this->actingAs($this->superAdmin)->patchJson(
            route('admin.administrateurs.role.attribuer', $this->superAdmin),
            ['role' => 'consultation', 'role_actuel' => 'super_admin']
        )->assertForbidden();

        $this->assertSame('super_admin', $this->superAdmin->fresh()->role);
    }

    public function test_dernier_super_admin_protege(): void
    {
        $this->actingAs($this->superAdmin)->patchJson(
            route('admin.administrateurs.role.attribuer', $this->superAdmin),
            ['role' => 'consultation', 'role_actuel' => 'super_admin']
        )->assertForbidden();

        // Avec un second super-admin actif, la rétrogradation devient possible.
        $second = $this->agent('super_admin');
        $this->actingAs($second)->patchJson(
            route('admin.administrateurs.role.attribuer', $this->superAdmin),
            ['role' => 'consultation', 'role_actuel' => 'super_admin']
        )->assertOk();
        $this->assertSame('consultation', $this->superAdmin->fresh()->role);
    }

    public function test_promotion_super_admin_possible_mais_tracee(): void
    {
        // Politique existante conservée (super_admin attribue super_admin),
        // avec avertissement UI — l'opération réussit et est auditée.
        $cible = $this->agent();

        $this->actingAs($this->superAdmin)->postJson(route('admin.roles.attribuer'), [
            'user_id' => $cible->id, 'role' => 'super_admin', 'role_actuel' => 'agent_scolarite',
        ])->assertOk();

        $this->assertSame('super_admin', $cible->fresh()->role);
        $this->assertStringContainsString('admin.role_attribue', $this->contenuAudit());
    }

    public function test_conflit_role_obsolete_409(): void
    {
        $cible = $this->agent();
        $autre = $this->agent('super_admin');

        // Changement concurrent par un second acteur…
        $this->actingAs($autre)->patchJson(
            route('admin.administrateurs.role.attribuer', $cible),
            ['role' => 'consultation', 'role_actuel' => 'agent_scolarite']
        )->assertOk();

        // …puis soumission obsolète du premier (rôle attendu périmé).
        $this->actingAs($this->superAdmin)->patchJson(
            route('admin.administrateurs.role.attribuer', $cible),
            ['role' => 'admin_scolarite', 'role_actuel' => 'agent_scolarite']
        )->assertStatus(409);

        $this->assertSame('consultation', $cible->fresh()->role);
    }

    public function test_recherche_comptes_bornee_et_filtree(): void
    {
        $this->actingAs($this->superAdmin)
            ->getJson(route('admin.administrateurs.recherche', ['q' => 'x']))
            ->assertStatus(422);

        $reponse = $this->actingAs($this->superAdmin)
            ->getJson(route('admin.administrateurs.recherche', ['q' => 'Super']))
            ->assertOk();

        $comptes = $reponse->json('comptes');
        $this->assertNotEmpty($comptes);
        $this->assertLessThanOrEqual(10, count($comptes));
        $this->assertArrayNotHasKey('password', $comptes[0]);
    }

    /* ── Intégrité et audit ── */

    public function test_audit_succes_complet_sans_secret(): void
    {
        $cible = $this->agent();

        $reponse = $this->actingAs($this->superAdmin)->patchJson(
            route('admin.administrateurs.role.attribuer', $cible),
            ['role' => 'admin_scolarite', 'role_actuel' => 'agent_scolarite']
        )->assertOk();

        $journal = $this->contenuAudit();
        $correlation = $reponse->json('correlation');
        $this->assertNotEmpty($correlation);
        $this->assertStringContainsString('admin.role_attribue', $journal);
        $this->assertStringContainsString($correlation, $journal);
        $this->assertStringContainsString('menu_comptes', $journal);
        $this->assertStringContainsString('agent_scolarite', $journal);
        $this->assertStringContainsString('admin_scolarite', $journal);
        $this->assertStringNotContainsStringIgnoringCase('password', $journal);
        $this->assertStringNotContainsStringIgnoringCase('token', $journal);
    }

    public function test_refus_journalise_avec_motif(): void
    {
        $this->actingAs($this->superAdmin)->patchJson(
            route('admin.administrateurs.role.attribuer', $this->superAdmin),
            ['role' => 'consultation', 'role_actuel' => 'super_admin']
        )->assertForbidden();

        $journal = $this->contenuAudit();
        $this->assertStringContainsString('admin.role_refuse', $journal);
        $this->assertStringContainsString('menu_comptes', $journal);
    }

    public function test_matrice_permissions_intacte(): void
    {
        // 'certificateur_qualite', 'direction_etudes', 'autorite_publication' :
        // rôles DÉDIÉS à chaque étape du circuit de gouvernance des examens
        // (§2.7), pour rendre la séparation des pouvoirs par rôle effective.
        // 'auditeur' : rôle d'AUDIT distinct ajouté par le Lot 19 (cahier
        // gouvernance) — lecture seule, jamais de gestion de rôles.
        $this->assertSame(
            [
                'super_admin', 'admin_scolarite', 'agent_scolarite', 'consultation',
                'admin_faculte', 'certificateur_qualite', 'direction_etudes',
                'autorite_publication', 'auditeur',
            ],
            Permissions::ROLES
        );
        foreach ([
            'admin_scolarite', 'admin_faculte', 'agent_scolarite', 'consultation',
            'certificateur_qualite', 'direction_etudes', 'autorite_publication', 'auditeur',
        ] as $role) {
            $this->assertNotContains('roles.view', Permissions::pourRole($role), $role);
            $this->assertNotContains('roles.manage', Permissions::pourRole($role), $role);
        }
    }

    public function test_pas_de_n_plus_un_sur_liste(): void
    {
        $this->actingAs($this->superAdmin);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('admin.administrateurs.index'))->assertOk();
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(30, $requetes, "Liste des comptes : {$requetes} requêtes");
    }
}
