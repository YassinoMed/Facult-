<?php

namespace Tests\Feature;

use App\Models\Iam\DemandeIam;
use App\Models\Iam\ProfilIam;
use App\Models\User;
use App\Services\Iam\ServiceProfilsIam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConstruitContexteIam;
use Tests\TestCase;

/**
 * LOOP GLOBAL IAM/RBAC — cycle de vie, permissions et anti-escalade.
 *
 * Couvre RBAC-015 à RBAC-028 de la matrice adaptée : suspension avec
 * révocation effective, réactivation, archivage, suppression protégée,
 * éditeur de permissions, dépendances, permissions réservées et
 * aiguillage vers le circuit à quatre yeux.
 */
class IamPermissionsEtCycleDeVieTest extends TestCase
{
    use ConstruitContexteIam;
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = $this->creerSuperAdmin();
    }

    public function test_rbac_015_suspension_revoque_effectivement_la_permission(): void
    {
        $profil = $this->creerProfilActif($this->superAdmin, ['finances.view'], [
            'code' => 'perso_suspension',
        ]);
        $cible = $this->creerCompte('agent_scolarite');

        $this->affecter($cible, $profil, $this->superAdmin);
        $this->assertTrue($cible->fresh()->hasPermission('finances.view'));

        $this->actingAs($this->superAdmin)
            ->post(route('admin.roles.suspendre', $profil->id), ['motif' => 'Suspension de test'])
            ->assertRedirect();

        $this->assertSame(ProfilIam::STATUT_SUSPENDU, $profil->fresh()->statut);
        $this->assertFalse($cible->fresh()->hasPermission('finances.view'));
        $this->assertDatabaseHas('iam_evenements', ['type' => 'iam.profil.suspendu']);
    }

    public function test_rbac_016_reactivation_restaure_l_effet_des_affectations_existantes(): void
    {
        $profil = $this->creerProfilActif($this->superAdmin, ['finances.view'], [
            'code' => 'perso_reactivation',
        ]);
        $cible = $this->creerCompte('agent_scolarite');

        $this->affecter($cible, $profil, $this->superAdmin);

        app(ServiceProfilsIam::class)->suspendre($profil, $this->superAdmin, 'Suspension de test');
        $this->assertFalse($cible->fresh()->hasPermission('finances.view'));

        $this->actingAs($this->superAdmin)
            ->post(route('admin.roles.reactiver', $profil->id))
            ->assertRedirect();

        $profil->refresh();

        $this->assertSame(ProfilIam::STATUT_ACTIF, $profil->statut);
        $this->assertSame(3, $profil->version);
        $this->assertTrue($cible->fresh()->hasPermission('finances.view'));
        $this->assertDatabaseHas('iam_evenements', ['type' => 'iam.profil.reactive']);
    }

    public function test_rbac_017_archivage_bloque_toute_modification_ulterieure(): void
    {
        $profil = $this->creerProfilActif($this->superAdmin, ['notes.view'], [
            'code' => 'perso_archive',
        ]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.roles.archiver', $profil->id))
            ->assertRedirect();

        $this->assertSame(ProfilIam::STATUT_ARCHIVE, $profil->fresh()->statut);

        $this->actingAs($this->superAdmin)
            ->patch(route('admin.roles.update', $profil->id), ['nom' => 'Modification interdite'])
            ->assertStatus(409);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.roles.permissions.update', $profil->id), [
                'permissions' => ['notes.view'],
            ])
            ->assertStatus(409);
    }

    public function test_rbac_018_suppression_physique_d_un_brouillon_sans_affectation(): void
    {
        $profil = $this->creerProfil($this->superAdmin, [], ['code' => 'perso_supprimable']);

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.roles.destroy', $profil->id))
            ->assertRedirect(route('admin.roles.index'));

        $this->assertDatabaseMissing('iam_profils', ['id' => $profil->id]);
        $this->assertDatabaseHas('iam_evenements', ['type' => 'iam.profil.supprime']);
    }

    public function test_rbac_019_suppression_refusee_si_historique_ou_role_systeme(): void
    {
        $profil = $this->creerProfilActif($this->superAdmin, ['notes.view'], [
            'code' => 'perso_historique',
        ]);
        $cible = $this->creerCompte('agent_scolarite');
        $this->affecter($cible, $profil, $this->superAdmin);

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.roles.destroy', $profil->id))
            ->assertStatus(409);

        $this->assertDatabaseHas('iam_profils', ['id' => $profil->id]);

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.roles.destroy', 'admin_scolarite'))
            ->assertForbidden();
    }

    public function test_rbac_020_editeur_de_permissions_affiche_la_hierarchie(): void
    {
        $profil = $this->creerProfil($this->superAdmin, ['notes.view'], [
            'code' => 'perso_editeur',
        ]);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.roles.permissions.edit', $profil->id))
            ->assertOk()
            ->assertSee('Sélection des permissions')
            ->assertSee('Catalogue total')
            ->assertSee('notes.view');
    }

    public function test_rbac_021_synchronisation_des_permissions_par_le_super_admin(): void
    {
        $profil = $this->creerProfil($this->superAdmin, [], ['code' => 'perso_sync']);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.roles.permissions.update', $profil->id), [
                'permissions' => ['notes.view', 'notes.create'],
                'motif' => 'Extension du périmètre de test',
            ])
            ->assertRedirect(route('admin.roles.show', $profil->id));

        $profil->refresh();

        $this->assertSame(2, $profil->version);
        $this->assertEqualsCanonicalizing(
            ['notes.view', 'notes.create'],
            $profil->codesPermissions()
        );
        $this->assertDatabaseHas('iam_evenements', [
            'type' => 'iam.profil.permissions_modifiees',
            'objet_id' => $profil->id,
        ]);
    }

    public function test_rbac_022_permission_inconnue_refusee_a_la_mise_a_jour(): void
    {
        $profil = $this->creerProfil($this->superAdmin, [], ['code' => 'perso_inconnue']);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.roles.permissions.update', $profil->id), [
                'permissions' => ['permission.inexistante'],
            ])
            ->assertStatus(422);

        $this->assertSame([], $profil->fresh()->codesPermissions());
    }

    public function test_rbac_023_dependance_manquante_refusee(): void
    {
        $profil = $this->creerProfil($this->superAdmin, [], ['code' => 'perso_dependance']);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.roles.permissions.update', $profil->id), [
                'permissions' => ['notes.create'],
            ])
            ->assertStatus(422);

        $this->assertSame([], $profil->fresh()->codesPermissions());
    }

    public function test_rbac_024_permission_reservee_iam_refusee(): void
    {
        $profil = $this->creerProfil($this->superAdmin, [], ['code' => 'perso_reserve']);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.roles.permissions.update', $profil->id), [
                'permissions' => ['iam.consulter'],
            ])
            ->assertForbidden();

        $this->assertSame([], $profil->fresh()->codesPermissions());
    }

    public function test_rbac_025_ajout_critique_non_super_cree_une_demande_sans_effet(): void
    {
        $acteur = $this->creerCompte('admin_scolarite');
        $this->accorderPermissions($acteur, ['roles.view', 'roles.manage']);

        $profil = $this->creerProfil($this->superAdmin, ['notes.view', 'notes.update'], [
            'code' => 'perso_critique',
        ]);

        $this->actingAs($acteur)
            ->post(route('admin.roles.permissions.update', $profil->id), [
                'permissions' => ['notes.view', 'notes.update', 'notes.unlock'],
                'motif' => 'Déverrouillage exceptionnel',
            ])
            ->assertRedirect(route('admin.roles.show', $profil->id))
            ->assertSessionHas('info');

        $this->assertNotContains('notes.unlock', $profil->fresh()->codesPermissions());

        $demande = DemandeIam::where('type', DemandeIam::TYPE_ACTIVATION_PROFIL)->firstOrFail();

        $this->assertSame(DemandeIam::STATUT_SOUMISE, $demande->statut);
        $this->assertContains('notes.unlock', $demande->payload['permissions']);
    }

    public function test_rbac_026_ajout_non_critique_non_super_applique_si_permission_detenu(): void
    {
        $acteur = $this->creerCompte('admin_scolarite');
        $this->accorderPermissions($acteur, ['roles.view', 'roles.manage', 'notes.view']);

        $profil = $this->creerProfil($this->superAdmin, [], ['code' => 'perso_non_critique']);

        $this->actingAs($acteur)
            ->post(route('admin.roles.permissions.update', $profil->id), [
                'permissions' => ['notes.view'],
                'motif' => 'Consultation des notes',
            ])
            ->assertRedirect(route('admin.roles.show', $profil->id))
            ->assertSessionHas('success');

        $this->assertContains('notes.view', $profil->fresh()->codesPermissions());
        $this->assertDatabaseCount('iam_demandes', 0);
    }

    public function test_rbac_027_anti_escalade_permission_non_detenu_refusee(): void
    {
        $acteur = $this->creerCompte('admin_scolarite');
        $this->accorderPermissions($acteur, ['roles.view', 'roles.manage']);

        $profil = $this->creerProfil($this->superAdmin, [], ['code' => 'perso_escalade']);

        $this->actingAs($acteur)
            ->post(route('admin.roles.permissions.update', $profil->id), [
                'permissions' => ['settings.view'],
            ])
            ->assertForbidden();

        $this->assertSame([], $profil->fresh()->codesPermissions());
    }

    public function test_rbac_028_page_affectations_liste_les_comptes_eligibles(): void
    {
        $profil = $this->creerProfilActif($this->superAdmin, ['notes.view'], [
            'code' => 'perso_affectations_page',
        ]);
        $cible = $this->creerCompte('agent_scolarite');

        $this->actingAs($this->superAdmin)
            ->get(route('admin.roles.affectations.index', $profil->id))
            ->assertOk()
            ->assertSee('Nouvelle affectation')
            ->assertSee($cible->name);
    }
}
