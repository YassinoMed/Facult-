<?php

namespace Tests\Feature;

use App\Models\Iam\ApprobationIam;
use App\Models\Iam\DemandeIam;
use App\Models\Iam\EvenementIam;
use App\Models\Iam\ProfilIam;
use App\Models\User;
use App\Services\Iam\ServiceAuditIam;
use App\Services\Iam\ServiceDemandesIam;
use App\Services\Iam\ServiceProfilsIam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Tests\Concerns\ConstruitContexteIam;
use Tests\TestCase;

/**
 * LOOP GLOBAL IAM/RBAC — circuit à quatre yeux, intégrité et audit.
 *
 * Couvre RBAC-036 à RBAC-045 : demande d'activation, auto-approbation,
 * bénéficiaire, payload scellé, expiration, quorum, isolation facultaire,
 * annulation, types non exécutables et journal append-only.
 */
class IamQuatreYeuxTest extends TestCase
{
    use ConstruitContexteIam;
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = $this->creerSuperAdmin();
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public function test_rbac_036_activation_non_super_soumise_puis_approuvee_par_un_tiers(): void
    {
        $initiateur = $this->creerCompte('admin_scolarite');
        $this->accorderPermissions($initiateur, ['roles.view', 'roles.manage']);

        $approbateur = $this->creerCompte('admin_scolarite');
        $this->accorderPermissions($approbateur, ['roles.view', 'roles.manage']);

        $profil = $this->creerProfil($this->superAdmin, ['notes.view'], [
            'code' => 'perso_activation_4yeux',
        ]);

        $this->actingAs($initiateur)
            ->post(route('admin.roles.activer', $profil->id))
            ->assertRedirect()
            ->assertSessionHas('info');

        $this->assertSame(ProfilIam::STATUT_BROUILLON, $profil->fresh()->statut);

        $demande = DemandeIam::where('type', DemandeIam::TYPE_ACTIVATION_PROFIL)->firstOrFail();

        $this->actingAs($approbateur)
            ->post(route('admin.roles.demandes.decider', $demande->id), [
                'decision' => ApprobationIam::DECISION_APPROUVE,
                'commentaire' => 'Activation validée par un tiers.',
            ])
            ->assertRedirect(route('admin.roles.demandes.index'));

        $this->assertSame(DemandeIam::STATUT_APPROUVEE, $demande->fresh()->statut);
        $this->assertSame(ProfilIam::STATUT_ACTIF, $profil->fresh()->statut);
        $this->assertSame($approbateur->id, $profil->fresh()->approuve_par_id);
        $this->assertDatabaseHas('iam_approbations', [
            'demande_id' => $demande->id,
            'approbateur_id' => $approbateur->id,
            'decision' => ApprobationIam::DECISION_APPROUVE,
        ]);
        $this->assertDatabaseHas('iam_evenements', [
            'type' => 'iam.demande.approuvee',
            'objet_id' => $demande->id,
        ]);

        $chaine = app(ServiceAuditIam::class)->verifierChaine();
        $this->assertTrue($chaine['valide']);
        $this->assertGreaterThan(0, $chaine['total']);
    }

    public function test_rbac_037_auto_approbation_interdite(): void
    {
        $initiateur = $this->creerCompte('admin_scolarite');
        $this->accorderPermissions($initiateur, ['roles.view', 'roles.manage']);

        $profil = $this->creerProfil($this->superAdmin, ['notes.view'], [
            'code' => 'perso_auto_approbation',
        ]);

        $demande = $this->creerDemande(
            DemandeIam::TYPE_ACTIVATION_PROFIL,
            $initiateur,
            profil: $profil,
            payload: ['permissions' => ['notes.view']],
        );

        $this->actingAs($initiateur)
            ->post(route('admin.roles.demandes.decider', $demande->id), [
                'decision' => ApprobationIam::DECISION_APPROUVE,
            ])
            ->assertForbidden();

        $this->assertSame(DemandeIam::STATUT_SOUMISE, $demande->fresh()->statut);
        $this->assertSame(0, ApprobationIam::where('demande_id', $demande->id)->count());
    }

    public function test_rbac_038_beneficiaire_ne_peut_pas_approuver_sa_propre_affectation(): void
    {
        $beneficiaire = $this->creerCompte('admin_scolarite');
        $this->accorderPermissions($beneficiaire, ['roles.view', 'roles.manage']);

        $profil = $this->creerProfilActif($this->superAdmin, ['notes.view'], [
            'code' => 'perso_beneficiaire',
        ]);

        $demande = $this->creerDemande(
            DemandeIam::TYPE_AFFECTATION_PROFIL,
            $this->superAdmin,
            cible: $beneficiaire,
            profil: $profil,
            payload: ['motif' => 'Affectation soumise à validation'],
        );

        $this->actingAs($beneficiaire)
            ->post(route('admin.roles.demandes.decider', $demande->id), [
                'decision' => ApprobationIam::DECISION_APPROUVE,
            ])
            ->assertForbidden();

        $this->assertSame(DemandeIam::STATUT_SOUMISE, $demande->fresh()->statut);
        $this->assertDatabaseCount('iam_affectations', 0);
    }

    public function test_rbac_039_payload_altere_et_version_obsolete_refuses(): void
    {
        $initiateur = $this->creerCompte('admin_scolarite');
        $this->accorderPermissions($initiateur, ['roles.view', 'roles.manage']);

        $approbateur = $this->creerCompte('admin_scolarite');
        $this->accorderPermissions($approbateur, ['roles.view', 'roles.manage']);

        $profilAltere = $this->creerProfil($this->superAdmin, ['notes.view'], [
            'code' => 'perso_payload_altere',
        ]);
        $demandeAlteree = $this->creerDemande(
            DemandeIam::TYPE_ACTIVATION_PROFIL,
            $initiateur,
            profil: $profilAltere,
            payload: ['permissions' => ['notes.view']],
        );

        $payload = $demandeAlteree->payload;
        $payload['permissions'] = ['archives.view'];
        $demandeAlteree->payload = $payload;
        $demandeAlteree->save();

        $this->actingAs($approbateur)
            ->post(route('admin.roles.demandes.decider', $demandeAlteree->id), [
                'decision' => ApprobationIam::DECISION_APPROUVE,
            ])
            ->assertStatus(409);

        $this->assertSame(DemandeIam::STATUT_SOUMISE, $demandeAlteree->fresh()->statut);
        $this->assertDatabaseHas('iam_evenements', ['type' => 'iam.demande.integrite_refusee']);

        $profilObsolete = $this->creerProfil($this->superAdmin, ['notes.view'], [
            'code' => 'perso_version_obsolete',
        ]);
        $demandeObsolete = $this->creerDemande(
            DemandeIam::TYPE_ACTIVATION_PROFIL,
            $initiateur,
            profil: $profilObsolete,
            payload: ['permissions' => ['notes.view']],
        );

        app(ServiceProfilsIam::class)->modifier(
            $profilObsolete,
            nom: 'Rôle modifié après demande',
            modificateur: $this->superAdmin,
        );

        $this->actingAs($approbateur)
            ->post(route('admin.roles.demandes.decider', $demandeObsolete->id), [
                'decision' => ApprobationIam::DECISION_APPROUVE,
            ])
            ->assertStatus(409);

        $this->assertSame(DemandeIam::STATUT_SOUMISE, $demandeObsolete->fresh()->statut);
        $this->assertDatabaseHas('iam_evenements', ['type' => 'iam.demande.obsolete_refusee']);
    }

    public function test_rbac_040_demande_expiree_refusee_et_scellee(): void
    {
        $initiateur = $this->creerCompte('admin_scolarite');
        $this->accorderPermissions($initiateur, ['roles.view', 'roles.manage']);

        $approbateur = $this->creerCompte('admin_scolarite');
        $this->accorderPermissions($approbateur, ['roles.view', 'roles.manage']);

        $profil = $this->creerProfil($this->superAdmin, ['notes.view'], [
            'code' => 'perso_expiration',
        ]);
        $demande = $this->creerDemande(
            DemandeIam::TYPE_ACTIVATION_PROFIL,
            $initiateur,
            profil: $profil,
            payload: ['permissions' => ['notes.view']],
        );

        $this->travel(73)->hours();

        $this->actingAs($approbateur)
            ->post(route('admin.roles.demandes.decider', $demande->id), [
                'decision' => ApprobationIam::DECISION_APPROUVE,
            ])
            ->assertStatus(409);

        $this->assertSame(DemandeIam::STATUT_EXPIREE, $demande->fresh()->statut);
        $this->assertSame(ProfilIam::STATUT_BROUILLON, $profil->fresh()->statut);
        $this->assertDatabaseHas('iam_evenements', ['type' => 'iam.demande.expiree']);
    }

    public function test_rbac_041_quorum_partiel_et_double_decision(): void
    {
        config()->set('iam.approbateurs_requis', 2);

        $initiateur = $this->creerCompte('admin_scolarite');
        $this->accorderPermissions($initiateur, ['roles.view', 'roles.manage']);

        $approbateur1 = $this->creerCompte('admin_scolarite');
        $this->accorderPermissions($approbateur1, ['roles.view', 'roles.manage']);

        $approbateur2 = $this->creerCompte('admin_scolarite');
        $this->accorderPermissions($approbateur2, ['roles.view', 'roles.manage']);

        $profil = $this->creerProfil($this->superAdmin, ['notes.view'], [
            'code' => 'perso_quorum',
        ]);
        $demande = $this->creerDemande(
            DemandeIam::TYPE_ACTIVATION_PROFIL,
            $initiateur,
            profil: $profil,
            payload: ['permissions' => ['notes.view']],
        );

        $this->actingAs($approbateur1)
            ->post(route('admin.roles.demandes.decider', $demande->id), [
                'decision' => ApprobationIam::DECISION_APPROUVE,
            ])
            ->assertRedirect();

        $this->assertSame(DemandeIam::STATUT_SOUMISE, $demande->fresh()->statut);
        $this->assertSame(ProfilIam::STATUT_BROUILLON, $profil->fresh()->statut);
        $this->assertDatabaseHas('iam_evenements', ['type' => 'iam.demande.approbation_partielle']);

        $this->actingAs($approbateur1)
            ->post(route('admin.roles.demandes.decider', $demande->id), [
                'decision' => ApprobationIam::DECISION_APPROUVE,
            ])
            ->assertStatus(409);

        $this->actingAs($approbateur2)
            ->post(route('admin.roles.demandes.decider', $demande->id), [
                'decision' => ApprobationIam::DECISION_APPROUVE,
            ])
            ->assertRedirect();

        $this->assertSame(DemandeIam::STATUT_APPROUVEE, $demande->fresh()->statut);
        $this->assertSame(ProfilIam::STATUT_ACTIF, $profil->fresh()->statut);
        $this->assertSame(2, ApprobationIam::where('demande_id', $demande->id)->count());
    }

    public function test_rbac_042_isolation_facultaire_des_demandes(): void
    {
        $faculteA = $this->creerFaculte('FAC-A');
        $faculteB = $this->creerFaculte('FAC-B');

        $adminA = $this->creerCompte('admin_faculte', $faculteA);
        $this->accorderPermissions($adminA, ['roles.view', 'roles.manage']);

        $profilB = $this->creerProfil($this->superAdmin, ['notes.view'], [
            'code' => 'perso_fac_b_demande',
            'faculte' => $faculteB,
        ]);

        $demande = $this->creerDemande(
            DemandeIam::TYPE_ACTIVATION_PROFIL,
            $this->superAdmin,
            profil: $profilB,
            faculte: $faculteB,
            payload: ['permissions' => ['notes.view']],
        );

        $this->actingAs($adminA)
            ->get(route('admin.roles.demandes.index'))
            ->assertOk()
            ->assertDontSee($demande->reference);

        $this->actingAs($adminA)
            ->get(route('admin.roles.demandes.show', $demande->id))
            ->assertNotFound();

        $this->actingAs($adminA)
            ->post(route('admin.roles.demandes.decider', $demande->id), [
                'decision' => ApprobationIam::DECISION_APPROUVE,
            ])
            ->assertNotFound();

        $this->assertSame(DemandeIam::STATUT_SOUMISE, $demande->fresh()->statut);
    }

    public function test_rbac_043_annulation_reservee_a_l_initiateur_ou_au_super_admin(): void
    {
        $initiateur = $this->creerCompte('admin_scolarite');
        $this->accorderPermissions($initiateur, ['roles.view', 'roles.manage']);

        $autre = $this->creerCompte('admin_scolarite');
        $this->accorderPermissions($autre, ['roles.view', 'roles.manage']);

        $profil = $this->creerProfil($this->superAdmin, ['notes.view'], [
            'code' => 'perso_annulation',
        ]);
        $demande = $this->creerDemande(
            DemandeIam::TYPE_ACTIVATION_PROFIL,
            $initiateur,
            profil: $profil,
            payload: ['permissions' => ['notes.view']],
        );

        $this->actingAs($autre)
            ->post(route('admin.roles.demandes.annuler', $demande->id))
            ->assertForbidden();

        $this->assertSame(DemandeIam::STATUT_SOUMISE, $demande->fresh()->statut);

        $this->actingAs($initiateur)
            ->post(route('admin.roles.demandes.annuler', $demande->id), [
                'motif' => 'Demande retirée par son initiateur.',
            ])
            ->assertRedirect(route('admin.roles.demandes.index'));

        $this->assertSame(DemandeIam::STATUT_ANNULEE, $demande->fresh()->statut);
        $this->assertDatabaseHas('iam_evenements', ['type' => 'iam.demande.annulee']);

        $chaine = app(ServiceAuditIam::class)->verifierChaine();
        $this->assertTrue($chaine['valide']);
    }

    public function test_rbac_044_type_de_demande_non_executable_refuse(): void
    {
        $acteur = $this->creerCompte('admin_scolarite');

        try {
            app(ServiceDemandesIam::class)->creerDemande(
                type: DemandeIam::TYPE_DELEGATION,
                initiateur: $acteur,
                motif: 'Type sans effet métier',
            );

            $this->fail('Une demande sans effet métier aurait dû être refusée.');
        } catch (UnprocessableEntityHttpException $exception) {
            $this->assertStringContainsString('pas encore exécutable', $exception->getMessage());
        }

        $this->assertDatabaseCount('iam_demandes', 0);
    }

    public function test_rbac_045_journal_iam_append_only_et_chaine_valide(): void
    {
        $profil = $this->creerProfil($this->superAdmin, ['notes.view'], [
            'code' => 'perso_audit_immuable',
        ]);

        $evenement = EvenementIam::where('objet_type', ProfilIam::class)
            ->where('objet_id', $profil->id)
            ->where('type', 'iam.profil.cree')
            ->firstOrFail();

        $evenement->type = 'iam.profil.falsifie';
        $this->assertFalse($evenement->save());

        $this->assertFalse($evenement->delete());

        $this->assertDatabaseHas('iam_evenements', [
            'id' => $evenement->id,
            'type' => 'iam.profil.cree',
        ]);

        $chaine = app(ServiceAuditIam::class)->verifierChaine();
        $this->assertTrue($chaine['valide']);
        $this->assertGreaterThan(0, $chaine['total']);
    }
}
