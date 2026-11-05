<?php

namespace Tests\Feature;

use App\Models\Iam\EvenementIam;
use App\Models\Iam\ProfilIam;
use App\Models\User;
use App\Services\Iam\ServiceProfilsIam;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConstruitContexteIam;
use Tests\TestCase;

/**
 * LOOP GLOBAL IAM/RBAC — CRUD et cycle de vie des rôles personnalisés.
 *
 * Couvre RBAC-001 à RBAC-014 de la matrice adaptée :
 * consultation, création en brouillon, validations serveur, protection
 * des rôles système, périmètre facultaire et activation.
 */
class IamRoleCrudTest extends TestCase
{
    use ConstruitContexteIam;
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = $this->creerSuperAdmin();
    }

    public function test_rbac_001_super_admin_consulte_la_gouvernance_des_roles(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.roles.index'))
            ->assertOk()
            ->assertSee('Gouvernance des Rôles')
            ->assertSee('Créer un rôle personnalisé');
    }

    public function test_rbac_002_agent_sans_permission_est_refuse(): void
    {
        $agent = $this->creerCompte('agent_scolarite');

        $this->actingAs($agent)->get(route('admin.roles.index'))->assertForbidden();
        $this->actingAs($agent)->get(route('admin.roles.create'))->assertForbidden();
        $this->actingAs($agent)->post(route('admin.roles.store'), [
            'code' => 'perso_interdit',
            'nom' => 'Rôle interdit',
        ])->assertForbidden();

        $this->assertDatabaseCount('iam_profils', 0);
    }

    public function test_rbac_003_creation_role_personnalise_en_brouillon_avec_audit(): void
    {
        $reponse = $this->actingAs($this->superAdmin)->post(route('admin.roles.store'), [
            'code' => 'perso_scolarite_test',
            'nom' => 'Scolarité de test',
            'description' => 'Rôle de test pour la gouvernance IAM.',
            'permissions' => ['notes.view'],
        ]);

        $profil = ProfilIam::where('code', 'perso_scolarite_test')->firstOrFail();

        $reponse->assertRedirect(route('admin.roles.show', $profil->id))
            ->assertSessionHas('success');

        $this->assertSame(ProfilIam::STATUT_BROUILLON, $profil->statut);
        // Version 1 à la création, incrémentée à 2 par la synchronisation
        // initiale des permissions (invariant du service).
        $this->assertSame(2, $profil->version);
        $this->assertSame($this->superAdmin->id, $profil->cree_par_id);
        $this->assertDatabaseHas('iam_profil_permissions', [
            'profil_id' => $profil->id,
            'permission_code' => 'notes.view',
        ]);
        $this->assertDatabaseHas('iam_evenements', ['type' => 'iam.profil.cree']);
    }

    public function test_rbac_004_collision_avec_un_role_systeme_refusee(): void
    {
        $this->actingAs($this->superAdmin)->post(route('admin.roles.store'), [
            'code' => 'super_admin',
            'nom' => 'Rôle pirate',
        ])->assertSessionHasErrors('code');

        $this->assertDatabaseCount('iam_profils', 0);
    }

    public function test_rbac_005_code_technique_invalide_refuse(): void
    {
        $this->actingAs($this->superAdmin)->post(route('admin.roles.store'), [
            'code' => 'Rôle Perso!',
            'nom' => 'Rôle invalide',
        ])->assertSessionHasErrors('code');

        $this->assertDatabaseCount('iam_profils', 0);
    }

    public function test_rbac_006_permission_inconnue_refusee_a_la_creation(): void
    {
        $this->actingAs($this->superAdmin)->post(route('admin.roles.store'), [
            'code' => 'perso_permission_inconnue',
            'nom' => 'Rôle invalide',
            'permissions' => ['permission.inexistante'],
        ])->assertStatus(422);

        $this->assertDatabaseCount('iam_profils', 0);
    }

    public function test_rbac_007_creation_directement_active_reservee_au_super_admin(): void
    {
        $acteur = $this->creerCompte('admin_scolarite');

        $this->expectException(AuthorizationException::class);

        app(ServiceProfilsIam::class)->creer(
            code: 'perso_activation_directe',
            nom: 'Activation directe interdite',
            createur: $acteur,
            statutInitial: ProfilIam::STATUT_ACTIF,
        );
    }

    public function test_rbac_008_fiche_role_systeme_protege(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.roles.show', 'super_admin'))
            ->assertOk()
            ->assertSee('super_admin')
            ->assertSee('Système protégé')
            ->assertSee('Permissions effectives');
    }

    public function test_rbac_009_fiche_role_personnalise_affiche_les_metadonnees(): void
    {
        $profil = $this->creerProfil($this->superAdmin, ['notes.view'], [
            'code' => 'perso_fiche',
            'nom' => 'Rôle avec fiche',
        ]);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.roles.show', $profil->id))
            ->assertOk()
            ->assertSee('perso_fiche')
            ->assertSee('Métadonnées de gouvernance')
            ->assertSee('Brouillon')
            ->assertSee($this->superAdmin->name)
            ->assertSee('notes.view');
    }

    public function test_rbac_010_modification_incremente_la_version_et_audite(): void
    {
        $profil = $this->creerProfil($this->superAdmin, [], ['code' => 'perso_modifiable']);

        $this->actingAs($this->superAdmin)->patch(route('admin.roles.update', $profil->id), [
            'nom' => 'Rôle modifié',
            'description' => 'Description modifiée.',
        ])->assertRedirect(route('admin.roles.show', $profil->id));

        $profil->refresh();

        $this->assertSame('Rôle modifié', $profil->nom);
        $this->assertSame(2, $profil->version);
        $this->assertDatabaseHas('iam_evenements', [
            'type' => 'iam.profil.modifie',
            'objet_id' => $profil->id,
        ]);
    }

    public function test_rbac_011_role_systeme_non_modifiable(): void
    {
        $this->actingAs($this->superAdmin)
            ->patch(route('admin.roles.update', 'admin_scolarite'), [
                'nom' => 'Tentative de modification',
            ])
            ->assertForbidden();
    }

    public function test_rbac_012_admin_faculte_ne_peut_pas_modifier_un_role_d_une_autre_faculte(): void
    {
        $faculteA = $this->creerFaculte('FAC-A');
        $faculteB = $this->creerFaculte('FAC-B');

        $adminA = $this->creerCompte('admin_faculte', $faculteA);
        $this->accorderPermissions($adminA, ['roles.view', 'roles.manage']);

        $profilB = $this->creerProfil($this->superAdmin, [], [
            'code' => 'perso_fac_b',
            'faculte' => $faculteB,
        ]);

        $this->actingAs($adminA)
            ->patch(route('admin.roles.update', $profilB->id), [
                'nom' => 'Modification hors périmètre',
            ])
            ->assertForbidden();
    }

    public function test_rbac_013_deplacement_facultaire_reserve_au_super_admin(): void
    {
        $faculteA = $this->creerFaculte('FAC-A');
        $faculteB = $this->creerFaculte('FAC-B');

        $adminA = $this->creerCompte('admin_faculte', $faculteA);
        $this->accorderPermissions($adminA, ['roles.view', 'roles.manage']);

        $profil = $this->creerProfil($this->superAdmin, [], [
            'code' => 'perso_portee',
            'faculte' => $faculteA,
        ]);

        $this->actingAs($adminA)
            ->patch(route('admin.roles.update', $profil->id), [
                'nom' => 'Rôle déplacé',
                'faculte_id' => $faculteB->id,
            ])
            ->assertForbidden();

        $this->assertSame($faculteA->id, $profil->fresh()->faculte_id);
    }

    public function test_rbac_014_activation_immediate_par_le_super_admin(): void
    {
        $profil = $this->creerProfil($this->superAdmin, ['notes.view'], [
            'code' => 'perso_activation_super',
        ]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.roles.activer', $profil->id))
            ->assertRedirect();

        $profil->refresh();

        $this->assertSame(ProfilIam::STATUT_ACTIF, $profil->statut);
        $this->assertSame($this->superAdmin->id, $profil->approuve_par_id);
        $this->assertNotNull($profil->approuve_le);
        $this->assertTrue(
            EvenementIam::where('type', 'iam.profil.active')->where('objet_id', $profil->id)->exists()
        );
    }
}
