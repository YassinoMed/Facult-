<?php

namespace Tests\Feature;

use App\Models\Faculte;
use App\Models\User;
use App\Modules\Versioning\Models\AuditVersioning;
use App\Modules\Versioning\Models\Deployment;
use App\Modules\Versioning\Models\Release;
use App\Modules\Versioning\Services\CompatibilityService;
use App\Modules\Versioning\Services\DeploymentService;
use App\Modules\Versioning\Services\DriftDetectionService;
use App\Modules\Versioning\Services\ManifestService;
use App\Modules\Versioning\Services\ReleaseService;
use App\Modules\Versioning\Services\VersioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VersioningTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $adminScolarite;

    protected User $adminFaculte;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('versioning.stockage.disque', 'local'));

        config(['modules.versioning.active' => true]);

        $faculte = Faculte::create([
            'nom' => 'Faculté des Sciences',
            'code' => 'FST',
            'description' => 'Sciences',
            'active' => true,
        ]);

        $this->superAdmin = User::creerCompteBackOffice([
            'name' => 'Super Admin',
            'email' => 'superadmin@universite.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin');

        $this->adminScolarite = User::creerCompteBackOffice([
            'name' => 'Admin Scolarité',
            'email' => 'scolarite@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->adminFaculte = User::creerCompteBackOffice([
            'name' => 'Admin Faculté',
            'email' => 'faculte@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $faculte->id,
        ], 'admin_faculte');
    }

    public function test_module_inactif_renvoie_404(): void
    {
        config(['modules.versioning.active' => false]);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.versioning.index'))
            ->assertNotFound();
    }

    public function test_versioning_service_parse_et_compare_versions(): void
    {
        $v = VersioningService::parseVersion('2.7.3-beta.1');
        $this->assertNotNull($v);
        $this->assertEquals(2, $v['major']);
        $this->assertEquals(7, $v['minor']);
        $this->assertEquals(3, $v['patch']);
        $this->assertEquals('beta.1', $v['pre']);

        $this->assertEquals(1, VersioningService::comparerVersions('2.0.0', '1.9.9'));
        $this->assertEquals(-1, VersioningService::comparerVersions('1.0.0', '1.0.1'));
        $this->assertEquals(0, VersioningService::comparerVersions('2.5.0', '2.5.0'));

        $this->assertEquals('1.0.1', VersioningService::incrementerVersion('1.0.0', 'patch'));
        $this->assertEquals('1.1.0', VersioningService::incrementerVersion('1.0.0', 'minor'));
        $this->assertEquals('2.0.0', VersioningService::incrementerVersion('1.0.0', 'major'));
    }

    public function test_release_service_creation_et_transitions_controles(): void
    {
        $releaseService = app(ReleaseService::class);

        $release = $releaseService->creer([
            'version' => '1.1.0',
            'environnement' => 'local',
            'app_version' => '1.1.0',
            'db_version' => '1.0.0',
            'notes' => 'Test notes',
        ], $this->superAdmin->id);

        $this->assertEquals(Release::STATUT_BROUILLON, $release->statut);
        $this->assertEquals('1.1.0', $release->version);

        // Transition brouillon -> preparee
        $release = $releaseService->transiter($release, Release::STATUT_PREPAREE, $this->superAdmin->id);
        $this->assertEquals(Release::STATUT_PREPAREE, $release->statut);

        // Transition preparee -> testee
        $release = $releaseService->transiter($release, Release::STATUT_TESTEE, $this->superAdmin->id);
        $this->assertEquals(Release::STATUT_TESTEE, $release->statut);

        // Transition testee -> approuvee
        $release = $releaseService->transiter($release, Release::STATUT_APPROUVEE, $this->superAdmin->id);
        $this->assertEquals(Release::STATUT_APPROUVEE, $release->statut);
        $this->assertEquals($this->superAdmin->id, $release->approved_by);

        // Transition interdite : approuvee -> brouillon doit lever une exception
        $this->expectException(\LogicException::class);
        $releaseService->transiter($release, Release::STATUT_BROUILLON);
    }

    public function test_verrouillage_optimiste_release(): void
    {
        $releaseService = app(ReleaseService::class);

        $release = $releaseService->creer([
            'version' => '1.2.0',
            'app_version' => '1.2.0',
            'db_version' => '1.0.0',
        ], $this->superAdmin->id);

        $cle = $releaseService->verrouiller($release);
        $this->assertNotNull($cle);
        $this->assertTrue($release->fresh()->estVerrouillee());

        // Tentative de second verrouillage immédiat doit échouer (renvoie null)
        $cle2 = $releaseService->verrouiller($release->fresh());
        $this->assertNull($cle2);

        // Déverrouillage
        $deverrouille = $releaseService->deverrouiller($release->fresh(), $cle);
        $this->assertTrue($deverrouille);
        $this->assertFalse($release->fresh()->estVerrouillee());
    }

    public function test_release_immuable_ne_peut_pas_etre_modifiee(): void
    {
        $releaseService = app(ReleaseService::class);

        $release = $releaseService->creer([
            'version' => '1.3.0',
            'app_version' => '1.3.0',
            'db_version' => '1.0.0',
        ], $this->superAdmin->id);

        $releaseService->transiter($release, Release::STATUT_PREPAREE);
        $releaseService->transiter($release, Release::STATUT_TESTEE);
        $release = $releaseService->transiter($release, Release::STATUT_APPROUVEE);

        $this->assertTrue($release->estImmutable());

        $this->expectException(\LogicException::class);
        $releaseService->mettreAJour($release, ['notes' => 'Modif interdite']);
    }

    public function test_compatibilite_semantique_et_pre_deploiement(): void
    {
        $compat = app(CompatibilityService::class);

        // Paire identique -> compatible
        $c1 = $compat->verifierPaire('2.0.0', '2.0.0');
        $this->assertEquals(CompatibilityService::STATUT_COMPATIBLE, $c1['statut']);

        // Paire mineure différente -> incompatible
        $c2 = $compat->verifierPaire('2.1.0', '2.0.0');
        $this->assertEquals(CompatibilityService::STATUT_INCOMPATIBLE, $c2['statut']);

        // Paire majeure différente -> incompatible
        $c3 = $compat->verifierPaire('3.0.0', '2.0.0');
        $this->assertEquals(CompatibilityService::STATUT_INCOMPATIBLE, $c3['statut']);

        // Paire patch tolérée
        config(['versioning.compatibilite.tolerance_patch' => 1]);
        $c4 = $compat->verifierPaire('2.0.1', '2.0.0');
        $this->assertEquals(CompatibilityService::STATUT_AVERTISSEMENT, $c4['statut']);

        // Checklist 10 points pré-déploiement
        $release = app(ReleaseService::class)->creer([
            'version' => '2.0.0',
            'app_version' => '2.0.0',
            'db_version' => '2.0.0',
        ]);
        app(ReleaseService::class)->transiter($release, Release::STATUT_PREPAREE);

        $check = $compat->verifierPreDeploiement($release);
        $this->assertArrayHasKey('release_cible', $check['points_controle']);
        $this->assertArrayHasKey('compatibilite_app', $check['points_controle']);
        $this->assertArrayHasKey('compatibilite_interne_release', $check['points_controle']);
        $this->assertArrayHasKey('migrations', $check['points_controle']);
        $this->assertArrayHasKey('configuration', $check['points_controle']);
        $this->assertArrayHasKey('dependances', $check['points_controle']);
        $this->assertArrayHasKey('manifest_integrite', $check['points_controle']);
    }

    public function test_drift_detection_identifie_les_ecarts(): void
    {
        $driftService = app(DriftDetectionService::class);
        $releaseService = app(ReleaseService::class);
        $manifestService = app(ManifestService::class);

        $release = $releaseService->creer([
            'version' => '9.9.9',
            'app_version' => '9.9.9',
            'db_version' => '9.9.9',
        ]);
        $releaseService->transiter($release, Release::STATUT_PREPAREE);
        $releaseService->transiter($release, Release::STATUT_TESTEE);
        $releaseService->transiter($release, Release::STATUT_APPROUVEE);
        $releaseService->transiter($release, Release::STATUT_EN_DEPLOIEMENT);
        $releaseService->transiter($release, Release::STATUT_DEPLOYEE);

        // Détection de dérive par rapport à cette release déployée 9.9.9 (alors que le système réel a une version différente)
        $rapport = $driftService->detecter($release);
        $this->assertTrue($rapport['drift_detecte']);
        $this->assertArrayHasKey('app_version', $rapport['ecarts']);
        $this->assertLessThan(100, $rapport['score_integrite']);
    }

    public function test_deploiement_complet_et_mise_a_jour_composants(): void
    {
        $deploymentService = app(DeploymentService::class);
        $releaseService = app(ReleaseService::class);
        $versioning = app(VersioningService::class);

        $release = $releaseService->creer([
            'version' => '1.5.0',
            'environnement' => 'local',
            'app_version' => '1.5.0',
            'db_version' => $versioning->versionDb(),
        ], $this->superAdmin->id);

        $releaseService->transiter($release, Release::STATUT_PREPAREE);
        $releaseService->transiter($release, Release::STATUT_TESTEE);
        $releaseService->transiter($release, Release::STATUT_APPROUVEE);

        $dep = $deploymentService->deployer($release, $this->superAdmin->id, [
            'sans_backup' => true, // En test mémoire
            'forcer' => true,
        ]);

        $this->assertEquals(Deployment::STATUT_REUSSI, $dep->statut);
        $this->assertEquals(Release::STATUT_DEPLOYEE, $release->fresh()->statut);
        $this->assertEquals('1.5.0', $versioning->versionApp());
        $this->assertNotNull($release->fresh()->deployed_at);
        $this->assertNotNull($release->fresh()->manifest_hash);

        // Vérifier enregistrement dans l'audit
        $auditExists = AuditVersioning::where('action', AuditVersioning::ACTION_DEPLOYMENT_SUCCEEDED)
            ->where('entite_id', $dep->id)
            ->exists();
        $this->assertTrue($auditExists);
    }

    public function test_rollback_planification_et_execution(): void
    {
        $deploymentService = app(DeploymentService::class);
        $releaseService = app(ReleaseService::class);

        // Release 1.0.0 initiale déployée
        $r1 = $releaseService->creer([
            'version' => '1.0.0',
            'app_version' => '1.0.0',
            'db_version' => '1.0.0',
        ]);
        $r1->update(['statut' => Release::STATUT_DEPLOYEE, 'deployed_at' => now()->subDay()]);

        // Release 1.1.0 déployée
        $r2 = $releaseService->creer([
            'version' => '1.1.0',
            'app_version' => '1.1.0',
            'db_version' => '1.0.0',
            'previous_release_id' => $r1->id,
        ]);
        $r2->update(['statut' => Release::STATUT_DEPLOYEE, 'deployed_at' => now()]);

        // Planifier le rollback vers r1
        $plan = $deploymentService->planifierRollback($r1);
        $this->assertTrue($plan['possible']);
        $this->assertEquals('1.1.0', $plan['release_actuelle']->version);
        $this->assertEquals('1.0.0', $plan['release_cible']->version);

        // Exécuter le rollback
        $depRollback = $deploymentService->rollback($r1, $this->superAdmin->id, [
            'rollback_db' => false,
        ]);

        $this->assertEquals(Deployment::TYPE_ROLLBACK, $depRollback->type);
        $this->assertEquals(Deployment::STATUT_REUSSI, $depRollback->statut);
        $this->assertEquals(Release::STATUT_ROLLBACKEE, $r2->fresh()->statut);
        $this->assertEquals(Release::STATUT_DEPLOYEE, $r1->fresh()->statut);
        $this->assertEquals('1.0.0', app(VersioningService::class)->versionApp());
    }

    public function test_comparaison_de_releases(): void
    {
        $releaseService = app(ReleaseService::class);

        $r1 = $releaseService->creer([
            'version' => '1.0.0',
            'app_version' => '1.0.0',
            'db_version' => '1.0.0',
            'notes' => 'Initiale',
        ]);

        $r2 = $releaseService->creer([
            'version' => '1.1.0',
            'app_version' => '1.1.0',
            'db_version' => '1.0.0',
            'notes' => 'Ajout fonctionnel',
        ]);

        $diff = $releaseService->comparer($r1, $r2);
        $this->assertEquals('mineure', $diff['type_evolution']);
        $this->assertTrue($diff['versions']['app_version']['modifie']);
        $this->assertFalse($diff['versions']['db_version']['modifie']);
    }

    public function test_securite_utilisateur_faculte_restreint_refuse_403(): void
    {
        // Un utilisateur rattaché à une faculté n'a pas accès à l'infrastructure globale
        $this->actingAs($this->adminFaculte)
            ->get(route('admin.versioning.index'))
            ->assertForbidden();
    }

    public function test_securite_super_admin_accede_a_toutes_les_vues(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.versioning.index'))
            ->assertOk();

        $this->actingAs($this->superAdmin)
            ->get(route('admin.versioning.drift'))
            ->assertOk();

        $this->actingAs($this->superAdmin)
            ->get(route('admin.versioning.compatibilite'))
            ->assertOk();

        $this->actingAs($this->superAdmin)
            ->get(route('admin.versioning.audit'))
            ->assertOk();

        $this->actingAs($this->superAdmin)
            ->get(route('admin.versioning.releases.index'))
            ->assertOk();

        $this->actingAs($this->superAdmin)
            ->get(route('admin.versioning.releases.create'))
            ->assertOk();
    }

    public function test_api_v1_version_public_accessible_sans_token(): void
    {
        $response = $this->getJson(route('api.v1.version'));

        $response->assertOk()
            ->assertJsonStructure([
                'status',
                'app_version',
                'db_version',
                'build',
                'environnement',
                'timestamp',
            ]);
    }

    public function test_api_v1_version_status_avec_authentification_sanctum(): void
    {
        $token = $this->superAdmin->createToken('test-versioning', ['versioning:read'])->plainTextToken;

        $response = $this->withToken($token)->getJson(route('api.v1.version.status'));

        $response->assertOk()
            ->assertJsonStructure([
                'etat' => [
                    'app_version',
                    'db_version',
                    'dev_version',
                    'prod_version',
                    'build',
                    'environnement',
                ],
                'timestamp',
            ]);
    }

    public function test_commandes_artisan_status_et_drift(): void
    {
        $this->artisan('versioning:status')->assertSuccessful();
        $this->artisan('versioning:drift')->assertSuccessful();
        $this->artisan('versioning:manifest --generer')->assertSuccessful();
    }

    public function test_web_creation_release_via_formulaire(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.versioning.releases.store'), [
                'version' => '3.0.0',
                'environnement' => 'local',
                'app_version' => '3.0.0',
                'db_version' => '1.0.0',
                'notes' => 'Mise à niveau majeure',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('releases', [
            'version' => '3.0.0',
            'statut' => Release::STATUT_BROUILLON,
        ]);
    }

    public function test_web_transition_release(): void
    {
        $release = app(ReleaseService::class)->creer([
            'version' => '3.1.0',
            'app_version' => '3.1.0',
            'db_version' => '1.0.0',
        ]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.versioning.releases.transition', $release), [
                'statut' => Release::STATUT_PREPAREE,
            ])
            ->assertRedirect();

        $this->assertEquals(Release::STATUT_PREPAREE, $release->fresh()->statut);
    }

    public function test_web_deploiement_avec_double_confirmation(): void
    {
        $versioning = app(VersioningService::class);
        $release = app(ReleaseService::class)->creer([
            'version' => '3.2.0',
            'environnement' => 'local',
            'app_version' => '3.2.0',
            'db_version' => $versioning->versionDb(),
        ]);
        app(ReleaseService::class)->transiter($release, Release::STATUT_PREPAREE);
        app(ReleaseService::class)->transiter($release, Release::STATUT_TESTEE);
        app(ReleaseService::class)->transiter($release, Release::STATUT_APPROUVEE);

        // Mauvaise confirmation -> erreur de validation
        $this->actingAs($this->superAdmin)
            ->post(route('admin.versioning.deployments.store', $release), [
                'confirmation' => 'MAUVAIS_CODE',
            ])
            ->assertSessionHasErrors(['confirmation']);

        // Bonne confirmation
        $this->actingAs($this->superAdmin)
            ->post(route('admin.versioning.deployments.store', $release), [
                'confirmation' => 'DEPLOYER',
                'sans_backup' => true,
                'forcer' => true,
            ])
            ->assertRedirect();

        $this->assertEquals(Release::STATUT_DEPLOYEE, $release->fresh()->statut);
    }

    public function test_securite_admin_scolarite_ne_peut_pas_deployer(): void
    {
        $release = app(ReleaseService::class)->creer([
            'version' => '3.3.0',
            'app_version' => '3.3.0',
            'db_version' => '1.0.0',
        ]);
        app(ReleaseService::class)->transiter($release, Release::STATUT_PREPAREE);
        app(ReleaseService::class)->transiter($release, Release::STATUT_TESTEE);
        app(ReleaseService::class)->transiter($release, Release::STATUT_APPROUVEE);

        // admin_scolarite n'a pas la permission versioning.deploy
        $this->actingAs($this->adminScolarite)
            ->get(route('admin.versioning.deployments.create', $release))
            ->assertForbidden();

        $this->actingAs($this->adminScolarite)
            ->post(route('admin.versioning.deployments.store', $release), [
                'confirmation' => 'DEPLOYER',
            ])
            ->assertForbidden();
    }

    /* ── Tests d'architecture — Correction Découplage SemVer & DB Schema ── */

    public function test_93_migrations_ne_produisent_jamais_app_1_0_93(): void
    {
        $versioning = app(VersioningService::class);

        // Même avec de nombreuses migrations appliquées, les versions SemVer restent conformes
        $this->assertEquals('1.0.0', $versioning->versionApp());
        $this->assertEquals('1.0.0', $versioning->dbRelease());
        $this->assertEquals('1.0.0', $versioning->versionDb());

        $this->assertNotEquals('1.0.93', $versioning->versionApp());
        $this->assertNotEquals('1.0.93', $versioning->dbRelease());
        $this->assertNotEquals('1.0.93', $versioning->versionDb());

        // Le compteur de migrations est confiné à dbSchema()
        $schema = $versioning->dbSchema();
        $this->assertIsArray($schema);
        $this->assertGreaterThan(0, $schema['applied']);
        $this->assertStringContainsString('migrations appliquées', $schema['texte']);
    }

    public function test_app_1_0_0_db_release_1_0_0_et_93_migrations_est_compatible(): void
    {
        $compatibility = app(CompatibilityService::class);
        $rapport = $compatibility->verifierSysteme();

        $this->assertTrue($rapport['compatible']);
        $this->assertEquals(CompatibilityService::STATUT_COMPATIBLE, $rapport['statut_global']);
        $this->assertEquals(CompatibilityService::STATUT_COMPATIBLE, $rapport['controles']['app_vs_db']['statut']);
    }

    public function test_app_1_0_0_avec_release_app_1_0_93_est_incompatibilite_semver_reelle(): void
    {
        $compatibility = app(CompatibilityService::class);

        // Écart de 93 patchs entre deux versions applicatives
        $verif = $compatibility->verifierPaire('1.0.93', '1.0.0', 'Release APP', 'Système APP');

        $this->assertEquals(CompatibilityService::STATUT_INCOMPATIBLE, $verif['statut']);
        $this->assertStringContainsString('Écart de correctif (patch) trop élevé', $verif['message']);
    }

    public function test_db_schema_different_ne_doit_pas_etre_confondu_avec_app_version(): void
    {
        $versioning = app(VersioningService::class);

        $etat = $versioning->etatCourant();
        $this->assertNotEquals($etat['app_version'], $etat['db_schema']['texte']);
        $this->assertEquals('1.0.0', $etat['app_version']);
        $this->assertEquals('1.0.0', $etat['db_release']);
        $this->assertIsInt($etat['db_schema']['applied']);
    }

    public function test_pending_migration_detectee_separement(): void
    {
        $compatibility = app(CompatibilityService::class);
        $versioning = app(VersioningService::class);

        // État de schéma technique
        $schema = $versioning->dbSchema();
        $this->assertArrayHasKey('pending', $schema);
        $this->assertArrayHasKey('applied', $schema);

        // Le contrôle pré-déploiement rapporte les migrations en attente sans polluer la compatibilité SemVer
        $release = app(ReleaseService::class)->creer([
            'version' => '1.0.0',
            'app_version' => '1.0.0',
            'db_release' => '1.0.0',
        ]);
        app(ReleaseService::class)->transiter($release, Release::STATUT_PREPAREE);

        $verif = $compatibility->verifierPreDeploiement($release);
        $this->assertArrayHasKey('migrations', $verif['points_controle']);
        $this->assertArrayHasKey('compatibilite_interne_release', $verif['points_controle']);
        $this->assertTrue($verif['points_controle']['compatibilite_interne_release']['succes']);
    }

    public function test_premiere_release_1_0_0_peut_etre_preparee(): void
    {
        $releaseService = app(ReleaseService::class);

        $release = $releaseService->creer([
            'version' => '1.0.0',
            'app_version' => '1.0.0',
            'db_release' => '1.0.0',
            'notes' => 'Première release officielle',
        ], $this->superAdmin->id);

        $releaseService->transiter($release, Release::STATUT_PREPAREE, $this->superAdmin->id);

        $this->assertEquals('1.0.0', $release->version);
        $this->assertEquals('1.0.0', $release->db_release);
        $this->assertEquals(Release::STATUT_PREPAREE, $release->statut);
        $this->assertNotNull($release->db_schema);
        $this->assertIsArray($release->db_schema);
    }

    public function test_premiere_release_1_0_0_peut_etre_deployee_si_conditions_satisfaites(): void
    {
        $releaseService = app(ReleaseService::class);
        $deploymentService = app(DeploymentService::class);

        $release = $releaseService->creer([
            'version' => '1.0.0',
            'app_version' => '1.0.0',
            'db_release' => '1.0.0',
        ], $this->superAdmin->id);

        $releaseService->transiter($release, Release::STATUT_PREPAREE);

        $dep = $deploymentService->deployer($release, $this->superAdmin->id, [
            'sans_backup' => true,
        ]);

        $this->assertEquals(Deployment::STATUT_REUSSI, $dep->statut);
        $this->assertEquals(Release::STATUT_DEPLOYEE, $release->fresh()->statut);
        $this->assertNotNull($release->fresh()->deployed_at);
        $this->assertEquals('1.0.0', app(VersioningService::class)->versionApp());
        $this->assertEquals('1.0.0', app(VersioningService::class)->dbRelease());
    }

    public function test_drift_db_schema_detecte_independamment_du_semver(): void
    {
        $driftService = app(DriftDetectionService::class);
        $releaseService = app(ReleaseService::class);
        $manifestService = app(ManifestService::class);

        $release = $releaseService->creer([
            'version' => '1.0.0',
            'app_version' => '1.0.0',
            'db_release' => '1.0.0',
        ]);
        $releaseService->transiter($release, Release::STATUT_PREPAREE);
        $releaseService->transiter($release, Release::STATUT_EN_DEPLOIEMENT);
        $release->update(['statut' => Release::STATUT_DEPLOYEE, 'deployed_at' => now()]);

        // Créer un manifest avec un schéma différent (ex: 80 migrations au lieu de 94)
        $manifest = $manifestService->generer([
            'version' => '1.0.0',
            'app_version' => '1.0.0',
            'db_release' => '1.0.0',
            'db_schema' => ['applied' => 80, 'pending' => 0, 'last' => 'dummy', 'texte' => '80 migrations appliquées'],
        ]);
        $manifestService->stocker($manifest, '1.0.0');

        try {
            $rapport = $driftService->detecter($release);

            $this->assertTrue($rapport['drift_detecte']);
            $this->assertArrayHasKey('db_schema', $rapport['ecarts']);
            // Pas de dérive applicative SemVer
            $this->assertArrayNotHasKey('app_version', $rapport['ecarts']);
            $this->assertArrayNotHasKey('db_release', $rapport['ecarts']);
        } finally {
            // Restaurer le manifest conforme pour ne pas polluer l'environnement
            $restaure = $manifestService->generer([
                'version' => '1.0.0',
                'app_version' => '1.0.0',
                'db_release' => '1.0.0',
            ]);
            $manifestService->stocker($restaure, '1.0.0');
        }
    }

    public function test_manifest_conserve_separement_app_version_db_release_et_db_schema(): void
    {
        $manifestService = app(ManifestService::class);

        $manifest = $manifestService->generer([
            'version' => '1.0.0',
            'app_version' => '1.0.0',
            'db_release' => '1.0.0',
            'db_version' => '1.0.0',
            'db_schema' => [
                'applied' => 94,
                'pending' => 0,
                'last' => '2026_10_04_180000_decouple_db_release_and_db_schema',
                'texte' => '94 migrations appliquées',
            ],
            'build_number' => '2026.10.04.001',
        ]);

        $this->assertArrayHasKey('app_version', $manifest);
        $this->assertArrayHasKey('db_release', $manifest);
        $this->assertArrayHasKey('db_schema', $manifest);
        $this->assertEquals('1.0.0', $manifest['app_version']);
        $this->assertEquals('1.0.0', $manifest['db_release']);
        $this->assertEquals(94, $manifest['db_schema']['applied']);
    }

    public function test_aucun_secret_ne_doit_apparaitre_dans_le_manifest(): void
    {
        $manifestService = app(ManifestService::class);

        $manifest = $manifestService->generer([
            'version' => '1.0.0',
            'app_version' => '1.0.0',
            'db_release' => '1.0.0',
        ]);

        $json = json_encode($manifest, JSON_UNESCAPED_UNICODE);

        // Vérification de non-exposition des secrets
        $this->assertStringNotContainsString(config('app.key'), $json);
        $this->assertStringNotContainsString('DB_PASSWORD', $json);
        $this->assertStringNotContainsString('REDIS_PASSWORD', $json);
    }
}
