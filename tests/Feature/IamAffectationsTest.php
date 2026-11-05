<?php

namespace Tests\Feature;

use App\Models\Iam\AffectationIam;
use App\Models\Iam\DemandeIam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConstruitContexteIam;
use Tests\TestCase;

/**
 * LOOP GLOBAL IAM/RBAC — affectations utilisateurs et révocation.
 *
 * Couvre RBAC-029 à RBAC-034 : affectation effective, rôle inactif,
 * auto-affectation, durée maximale, doublon et révocation immédiate.
 */
class IamAffectationsTest extends TestCase
{
    use ConstruitContexteIam;
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = $this->creerSuperAdmin();
    }

    public function test_rbac_029_affectation_active_et_permission_effective(): void
    {
        $profil = $this->creerProfilActif($this->superAdmin, ['finances.view'], [
            'code' => 'perso_affectation_ok',
        ]);
        $cible = $this->creerCompte('agent_scolarite');

        $this->actingAs($this->superAdmin)
            ->post(route('admin.roles.affectations.store', $profil->id), [
                'user_id' => $cible->id,
                'motif' => 'Affectation nominative de test',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $affectation = AffectationIam::where('user_id', $cible->id)->firstOrFail();

        $this->assertSame(AffectationIam::STATUT_ACTIVE, $affectation->statut);
        $this->assertSame($profil->version, $affectation->version_profil);
        $this->assertSame($this->superAdmin->id, $affectation->cree_par_id);
        $this->assertTrue($cible->fresh()->hasPermission('finances.view'));
        $this->assertDatabaseHas('iam_evenements', ['type' => 'iam.affectation.creee']);
    }

    public function test_rbac_030_role_inactif_non_affectable(): void
    {
        $profil = $this->creerProfil($this->superAdmin, ['finances.view'], [
            'code' => 'perso_inactif',
        ]);
        $cible = $this->creerCompte('agent_scolarite');

        $this->actingAs($this->superAdmin)
            ->post(route('admin.roles.affectations.store', $profil->id), [
                'user_id' => $cible->id,
                'motif' => 'Tentative sur rôle brouillon',
            ])
            ->assertSessionHasErrors('user_id');

        $this->assertDatabaseCount('iam_affectations', 0);
        $this->assertFalse($cible->fresh()->hasPermission('finances.view'));
    }

    public function test_rbac_031_auto_affectation_interdite(): void
    {
        $profil = $this->creerProfilActif($this->superAdmin, ['finances.view'], [
            'code' => 'perso_auto_affectation',
        ]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.roles.affectations.store', $profil->id), [
                'user_id' => $this->superAdmin->id,
                'motif' => 'Auto-affectation interdite',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('iam_affectations', 0);
    }

    public function test_rbac_032_duree_maximale_d_affectation_refusee(): void
    {
        $profil = $this->creerProfilActif($this->superAdmin, ['finances.view'], [
            'code' => 'perso_duree',
        ]);
        $cible = $this->creerCompte('agent_scolarite');

        $this->actingAs($this->superAdmin)
            ->post(route('admin.roles.affectations.store', $profil->id), [
                'user_id' => $cible->id,
                'expire_le' => now()->addDays(400)->toDateTimeString(),
                'motif' => 'Durée hors politique',
            ])
            ->assertSessionHasErrors('expire_le');

        $this->assertDatabaseCount('iam_affectations', 0);
    }

    public function test_rbac_033_affectation_doublon_refusee(): void
    {
        $profil = $this->creerProfilActif($this->superAdmin, ['finances.view'], [
            'code' => 'perso_doublon',
        ]);
        $cible = $this->creerCompte('agent_scolarite');

        $this->actingAs($this->superAdmin)
            ->post(route('admin.roles.affectations.store', $profil->id), [
                'user_id' => $cible->id,
                'motif' => 'Première affectation',
            ])
            ->assertRedirect();

        $this->actingAs($this->superAdmin)
            ->post(route('admin.roles.affectations.store', $profil->id), [
                'user_id' => $cible->id,
                'motif' => 'Deuxième affectation identique',
            ])
            ->assertStatus(409);

        $this->assertSame(1, AffectationIam::where('user_id', $cible->id)->count());
    }

    public function test_rbac_034_revocation_immediate_retire_la_permission(): void
    {
        $profil = $this->creerProfilActif($this->superAdmin, ['finances.view'], [
            'code' => 'perso_revocation',
        ]);
        $cible = $this->creerCompte('agent_scolarite');
        $affectation = $this->affecter($cible, $profil, $this->superAdmin);

        $this->assertTrue($cible->fresh()->hasPermission('finances.view'));

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.roles.affectations.destroy', $affectation->id), [
                'motif' => 'Fin de mission',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $affectation->refresh();

        $this->assertSame(AffectationIam::STATUT_REVOQUEE, $affectation->statut);
        $this->assertSame($this->superAdmin->id, $affectation->revoque_par_id);
        $this->assertNotNull($affectation->revoque_le);
        $this->assertFalse($cible->fresh()->hasPermission('finances.view'));
        $this->assertDatabaseHas('iam_evenements', ['type' => 'iam.affectation.revoquee']);
    }

    public function test_rbac_035_role_critique_non_super_passe_par_quatre_yeux(): void
    {
        $acteur = $this->creerCompte('admin_scolarite');
        $this->accorderPermissions($acteur, ['roles.view', 'roles.manage']);

        $profil = $this->creerProfilActif($this->superAdmin, ['finances.view', 'notes.view', 'notes.update', 'notes.unlock'], [
            'code' => 'perso_critique_affectation',
        ]);
        $cible = $this->creerCompte('agent_scolarite');

        $this->actingAs($acteur)
            ->post(route('admin.roles.affectations.store', $profil->id), [
                'user_id' => $cible->id,
                'motif' => 'Affectation à privilèges critiques',
            ])
            ->assertRedirect()
            ->assertSessionHas('info');

        $this->assertDatabaseCount('iam_affectations', 0);

        $demande = DemandeIam::where('type', DemandeIam::TYPE_AFFECTATION_PROFIL)->firstOrFail();

        $this->assertSame(DemandeIam::STATUT_SOUMISE, $demande->statut);
        $this->assertSame($cible->id, $demande->cible_user_id);
        $this->assertFalse($cible->fresh()->hasPermission('notes.unlock'));
    }
}
