<?php

namespace Tests\Feature;

use App\Contracts\SentinelCheckInterface;
use App\Models\AnneeUniversitaire;
use App\Models\AppNotification;
use App\Models\DatabaseBackup;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Sentinel\Jobs\ExecuterRondeSentinelJob;
use App\Modules\Sentinel\Models\SentinelIncident;
use App\Modules\Sentinel\Models\SentinelRegle;
use App\Modules\Sentinel\Services\Checks\ConfigurationPlateformeCheck;
use App\Modules\Sentinel\Services\Checks\OperationsSensiblesCheck;
use App\Modules\Sentinel\Services\Checks\StockagePriveCheck;
use App\Modules\Sentinel\Services\SentinelRegistry;
use App\Modules\Sentinel\Services\SentinelService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Platform Sentinel — incrément 1 : activation, cockpit, registre,
 * déduplication, isolation des contrôles en panne, commande planifiée.
 */
class SentinelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Registre frais par test (les stubs enregistrés ne fuient pas).
        app()->forgetInstance(SentinelRegistry::class);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Sentinelle',
            'email' => 'sentinelle@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    private function activer(bool $actif = true): void
    {
        Config::set('modules.sentinel.active', $actif);
    }

    public function test_module_desactive_repond_404(): void
    {
        $this->activer(false);

        $this->actingAs($this->admin)->get(route('admin.sentinel.index'))->assertNotFound();
    }

    public function test_cockpit_liste_incidents_et_compteurs(): void
    {
        $this->activer();

        SentinelIncident::create([
            'cle_unique' => sha1('TEST|a|1'),
            'code' => 'TEST', 'categorie' => 'integrite', 'gravite' => 'critique',
            'titre' => 'Incident test', 'diagnostic' => 'Diagnostic sans PII.',
            'statut' => SentinelIncident::STATUT_OUVERT,
            'detecte_le' => now(), 'derniere_detection_le' => now(),
        ]);

        $reponse = $this->actingAs($this->admin)->get(route('admin.sentinel.index'));

        $reponse->assertOk();
        $reponse->assertSee('Incident test', false);
        $reponse->assertSee('Critiques actifs', false);
    }

    public function test_invite_redirige(): void
    {
        $this->activer();

        $this->get(route('admin.sentinel.index'))->assertRedirect();
    }

    public function test_controle_emet_incident_deduplique(): void
    {
        app(SentinelRegistry::class)->enregistrer(new class implements SentinelCheckInterface
        {
            public function code(): string
            {
                return 'TEST_DEDUP';
            }

            public function categorie(): string
            {
                return 'integrite';
            }

            public function gravite(): string
            {
                return 'avertissement';
            }

            public function module(): ?string
            {
                return null;
            }

            public function controler(): array
            {
                return [[
                    'titre' => 'Constat répété',
                    'diagnostic' => 'Toujours présent.',
                    'entite_type' => 'test', 'entite_id' => 7,
                ]];
            }
        });

        $service = app(SentinelService::class);

        $premier = $service->executer('TEST_DEDUP');
        $second = $service->executer('TEST_DEDUP');

        $this->assertSame(1, $premier['executes']);
        $this->assertSame(1, $second['incidents']);
        $this->assertSame(1, SentinelIncident::where('code', 'TEST_DEDUP')->count());
        $this->assertSame(2, SentinelIncident::where('code', 'TEST_DEDUP')->first()->compteur);
    }

    public function test_controle_en_panne_bloque_ni_ronde_ni_incident(): void
    {
        app(SentinelRegistry::class)->enregistrer(new class implements SentinelCheckInterface
        {
            public function code(): string
            {
                return 'TEST_PANNE';
            }

            public function categorie(): string
            {
                return 'infrastructure';
            }

            public function gravite(): string
            {
                return 'erreur';
            }

            public function module(): ?string
            {
                return null;
            }

            public function controler(): array
            {
                throw new \RuntimeException('Sonde HS');
            }
        });

        $resultat = app(SentinelService::class)->executer('TEST_PANNE');

        $this->assertSame(['TEST_PANNE'], $resultat['erreurs']);
        $this->assertSame(0, SentinelIncident::where('code', 'TEST_PANNE')->count());
    }

    public function test_regle_desactivee_ignore_le_controle(): void
    {
        SentinelRegle::create([
            'code' => 'INFRA_BASE', 'categorie' => 'infrastructure',
            'gravite' => 'critique', 'actif' => false,
        ]);

        $resultat = app(SentinelService::class)->executer('INFRA_BASE');

        $this->assertSame(0, $resultat['executes']);
    }

    public function test_commande_ronde_environnement_sain(): void
    {
        Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        User::creerCompteBackOffice([
            'name' => 'Super', 'email' => 'super.sain2@universite.test', 'password' => 'Motdepasse@123',
        ], 'super_admin');
        DatabaseBackup::create([
            'uuid' => (string) Str::uuid(), 'filename' => 'db_sain.sql',
            'disk' => 'local', 'path' => sys_get_temp_dir().'/db_sain.sql', 'database_name' => 'test',
            'connexion' => 'mysql', 'status' => 'SUCCESS',
            'started_at' => now(), 'completed_at' => now(),
        ]);

        $this->artisan('sentinel:controler')->assertSuccessful();
        $this->assertSame(0, SentinelIncident::count());
    }

    public function test_transitions_incident_verrouillees(): void
    {
        $incident = new SentinelIncident(['statut' => SentinelIncident::STATUT_OUVERT]);

        $this->assertTrue($incident->peutTransiterVers(SentinelIncident::STATUT_RESOLU));
        $this->assertFalse($incident->peutTransiterVers('supprime'));
        $this->assertTrue($incident->estActif());
    }

    private function incidentOuvert(array $extra = []): SentinelIncident
    {
        return SentinelIncident::create(array_merge([
            'cle_unique' => sha1('TEST|'.uniqid()),
            'code' => 'TEST', 'categorie' => 'integrite', 'gravite' => 'erreur',
            'titre' => 'Incident test', 'diagnostic' => 'Diagnostic.',
            'statut' => SentinelIncident::STATUT_OUVERT,
            'detecte_le' => now(), 'derniere_detection_le' => now(),
        ], $extra));
    }

    private function enregistreControleSilencieux(string $code): void
    {
        app(SentinelRegistry::class)->enregistrer(new class($code) implements SentinelCheckInterface
        {
            public function __construct(private readonly string $codeVoulu) {}

            public function code(): string
            {
                return $this->codeVoulu;
            }

            public function categorie(): string
            {
                return 'integrite';
            }

            public function gravite(): string
            {
                return 'avertissement';
            }

            public function module(): ?string
            {
                return null;
            }

            public function controler(): array
            {
                return [];
            }
        });
    }

    public function test_transiter_cycle_de_vie_http(): void
    {
        $incident = $this->incidentOuvert();

        $this->actingAs($this->admin)->post(
            route('admin.sentinel.incidents.transiter', $incident), ['cible' => 'acquitte']
        )->assertRedirect();

        $this->assertSame('acquitte', $incident->fresh()->statut);

        $this->actingAs($this->admin)->post(
            route('admin.sentinel.incidents.transiter', $incident), ['cible' => 'resolu']
        )->assertRedirect();

        $this->assertNotNull($incident->fresh()->resolu_le);
    }

    public function test_transition_illegale_refusee(): void
    {
        $incident = $this->incidentOuvert(['statut' => SentinelIncident::STATUT_RESOLU]);

        $this->actingAs($this->admin)->post(
            route('admin.sentinel.incidents.transiter', $incident), ['cible' => 'acquitte']
        )->assertStatus(422);

        $mauvaisStatut = $this->actingAs($this->admin)->post(
            route('admin.sentinel.incidents.transiter', $incident), ['cible' => 'supprime']
        );

        $mauvaisStatut->assertRedirect();
        $mauvaisStatut->assertSessionHasErrors('cible');
    }

    public function test_transiter_sans_manage_interdit(): void
    {
        $incident = $this->incidentOuvert();
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.sentinel@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $this->actingAs($agent)->post(
            route('admin.sentinel.incidents.transiter', $incident), ['cible' => 'resolu']
        )->assertForbidden();
    }

    public function test_transiter_hors_perimetre_faculte_interdit(): void
    {
        $faculteA = Faculte::create(['nom' => 'FA', 'code' => 'FA', 'description' => 'T', 'active' => true]);
        $faculteB = Faculte::create(['nom' => 'FB', 'code' => 'FB', 'description' => 'T', 'active' => true]);
        $incident = $this->incidentOuvert(['faculte_id' => $faculteB->id]);
        $restreint = User::creerCompteBackOffice([
            'name' => 'Restreint', 'email' => 'restreint@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $faculteA->id,
        ], 'admin_faculte');

        $this->actingAs($restreint)->post(
            route('admin.sentinel.incidents.transiter', $incident), ['cible' => 'resolu']
        )->assertForbidden();
    }

    public function test_controle_silencieux_resout_incident_ouvert(): void
    {
        $incident = $this->incidentOuvert(['code' => 'TEST_AUTO']);
        $this->enregistreControleSilencieux('TEST_AUTO');

        app(SentinelService::class)->executer('TEST_AUTO');

        $this->assertSame('resolu', $incident->fresh()->statut);
        $this->assertNotNull($incident->fresh()->resolu_le);
    }

    public function test_critique_notifie_une_fois_cooldown_base(): void
    {
        User::creerCompteBackOffice([
            'name' => 'Super', 'email' => 'super@universite.test', 'password' => 'Motdepasse@123',
        ], 'super_admin');

        app(SentinelRegistry::class)->enregistrer(new class implements SentinelCheckInterface
        {
            public function code(): string
            {
                return 'TEST_CRITIQUE';
            }

            public function categorie(): string
            {
                return 'infrastructure';
            }

            public function gravite(): string
            {
                return 'critique';
            }

            public function module(): ?string
            {
                return null;
            }

            public function controler(): array
            {
                return [['titre' => 'Panne critique', 'diagnostic' => 'Tout est cassé.']];
            }
        });

        $service = app(SentinelService::class);
        $service->executer('TEST_CRITIQUE');
        $service->executer('TEST_CRITIQUE');

        $this->assertSame(1, AppNotification::where('type', 'sentinel.critique')->count());
        $this->assertNotNull(SentinelIncident::where('code', 'TEST_CRITIQUE')->first()->derniere_notification_le);
    }

    public function test_detail_incident_et_idor(): void
    {
        $incident = $this->incidentOuvert();

        $reponse = $this->actingAs($this->admin)->get(route('admin.sentinel.incidents.show', $incident));

        $reponse->assertOk();
        $reponse->assertSee('Diagnostic', false);

        $faculteA = Faculte::create(['nom' => 'FA2', 'code' => 'FA2', 'description' => 'T', 'active' => true]);
        $faculteB = Faculte::create(['nom' => 'FB2', 'code' => 'FB2', 'description' => 'T', 'active' => true]);
        $cloisonne = $this->incidentOuvert(['faculte_id' => $faculteB->id]);
        $restreint = User::creerCompteBackOffice([
            'name' => 'R2', 'email' => 'r2@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $faculteA->id,
        ], 'admin_faculte');

        $this->actingAs($restreint)->get(route('admin.sentinel.incidents.show', $cloisonne))
            ->assertForbidden();
    }

    public function test_regles_bascule_manage_uniquement(): void
    {
        $this->actingAs($this->admin)->get(route('admin.sentinel.regles'))
            ->assertOk()
            ->assertSee('INFRA_BASE', false);

        $this->actingAs($this->admin)->post(route('admin.sentinel.regles.basculer', 'INFRA_BASE'))
            ->assertRedirect();

        $this->assertFalse(SentinelRegle::where('code', 'INFRA_BASE')->first()->actif);

        // Contrôle désactivé : la ronde l'ignore.
        $this->assertSame(0, app(SentinelService::class)->executer('INFRA_BASE')['executes']);

        $agent = User::creerCompteBackOffice([
            'name' => 'Agent2', 'email' => 'agent2@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $this->actingAs($agent)->post(route('admin.sentinel.regles.basculer', 'INFRA_BASE'))
            ->assertForbidden();

        $this->actingAs($this->admin)->post(route('admin.sentinel.regles.basculer', 'CODE_INCONNU'))
            ->assertNotFound();
    }

    public function test_executer_ronde_manuelle_throttle_manage(): void
    {
        $this->actingAs($this->admin)->post(route('admin.sentinel.executer'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $agent = User::creerCompteBackOffice([
            'name' => 'Agent3', 'email' => 'agent3@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $this->actingAs($agent)->post(route('admin.sentinel.executer'))->assertForbidden();
    }

    public function test_notification_en_panne_n_echoue_pas_la_ronde(): void
    {
        $moqueur = \Mockery::mock(NotificationService::class);
        $moqueur->shouldReceive('notifierPlusieursUsers')->andThrow(new \RuntimeException('SMTP HS'));
        app()->instance(NotificationService::class, $moqueur);

        app(SentinelRegistry::class)->enregistrer(new class implements SentinelCheckInterface
        {
            public function code(): string
            {
                return 'TEST_CRITIQUE_HS';
            }

            public function categorie(): string
            {
                return 'infrastructure';
            }

            public function gravite(): string
            {
                return 'critique';
            }

            public function module(): ?string
            {
                return null;
            }

            public function controler(): array
            {
                return [['titre' => 'Panne critique', 'diagnostic' => 'Test.']];
            }
        });

        $resultat = app(SentinelService::class)->executer('TEST_CRITIQUE_HS');

        $this->assertSame([], $resultat['erreurs']);
        $this->assertSame(1, SentinelIncident::where('code', 'TEST_CRITIQUE_HS')->count());
    }

    public function test_ronde_saine_bornee_requetes_et_duree(): void
    {
        Faculte::create(['nom' => 'F', 'code' => 'FS', 'description' => 'T', 'active' => true]);
        AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        User::creerCompteBackOffice([
            'name' => 'Super', 'email' => 'super.bornes@universite.test', 'password' => 'Motdepasse@123',
        ], 'super_admin');

        DB::enableQueryLog();
        $debut = microtime(true);

        $resultat = app(SentinelService::class)->executer();

        $duree = microtime(true) - $debut;
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame([], $resultat['erreurs']);
        $this->assertLessThan(100, $requetes, 'Ronde saine : requêtes bornées.');
        $this->assertLessThan(30, $duree, 'Ronde saine : durée bornée.');
    }

    public function test_job_echoue_genere_incident_immediat_avec_sanitisation(): void
    {
        $this->activer();

        $connexion = 'database';
        $job = new SyncJob(app(), json_encode([
            'job' => 'App\\Jobs\\ExempleImportJob',
            'data' => [],
        ]), 'sync', 'default');
        $exception = new \RuntimeException('Erreur critique sur job token=secret123 in /var/www/secret/app.php');

        event(new JobFailed($connexion, $job, $exception));

        $incident = SentinelIncident::where('code', 'INFRA_JOB_ECHOUE')->first();
        $this->assertNotNull($incident);
        $this->assertSame('ouvert', $incident->statut);
        $this->assertSame('erreur', $incident->gravite);
        $this->assertStringContainsString('token=***', $incident->diagnostic);
        $this->assertStringNotContainsString('secret123', $incident->diagnostic);
    }

    public function test_job_echoue_deduplique_sur_echecs_multiples(): void
    {
        $this->activer();

        $connexion = 'database';
        $job = new SyncJob(app(), json_encode([
            'job' => 'App\\Jobs\\ExempleExportJob',
            'data' => [],
        ]), 'sync', 'exports');
        $exception = new \RuntimeException('Timeout de traitement');

        event(new JobFailed($connexion, $job, $exception));
        event(new JobFailed($connexion, $job, $exception));

        $this->assertSame(1, SentinelIncident::where('code', 'INFRA_JOB_ECHOUE')->count());
        $this->assertSame(2, SentinelIncident::where('code', 'INFRA_JOB_ECHOUE')->first()->compteur);
    }

    public function test_job_asynchrone_executer_ronde(): void
    {
        $job = new ExecuterRondeSentinelJob('INFRA_BASE');
        $this->assertSame(['sentinel', 'ronde', 'INFRA_BASE'], $job->tags());

        $mock = \Mockery::mock(SentinelService::class);
        $mock->shouldReceive('executer')->once()->with('INFRA_BASE')->andReturn([
            'executes' => 1, 'incidents' => 0, 'erreurs' => [], 'duree_ms' => 4,
        ]);

        $job->handle($mock);
    }

    public function test_configuration_plateforme_detecte_absence_annee_active(): void
    {
        $check = new ConfigurationPlateformeCheck;
        $constats = $check->controler();

        $this->assertNotEmpty($constats);
        $this->assertSame('CONFIG_COHERENCE', $check->code());
        $this->assertSame('configuration', $check->categorie());
    }

    public function test_operations_sensibles_detecte_absence_super_admin(): void
    {
        $check = new OperationsSensiblesCheck;
        $constats = $check->controler();

        $this->assertNotEmpty($constats);
        $this->assertSame('SECURITE_COMPTES_ADMIN', $check->code());
        $this->assertSame('securite', $check->categorie());
        $this->assertSame('critique', $check->gravite());
    }

    public function test_stockage_prive_check_est_inscriptible(): void
    {
        $check = new StockagePriveCheck;
        $this->assertSame('INFRA_STOCKAGE_PRIVE', $check->code());
        $this->assertSame('infrastructure', $check->categorie());
        $this->assertSame('critique', $check->gravite());

        $constats = $check->controler();
        $this->assertIsArray($constats);
    }

    public function test_recherche_et_filtres_cockpit(): void
    {
        $this->activer();

        $this->incidentOuvert(['titre' => 'Alerte Alpha Unique', 'code' => 'ALPHA', 'module' => 'scolarite']);
        $this->incidentOuvert(['titre' => 'Alerte Beta', 'code' => 'BETA', 'module' => 'examens']);

        $reponseAlpha = $this->actingAs($this->admin)->get(route('admin.sentinel.index', ['q' => 'Alpha']));
        $reponseAlpha->assertOk();
        $reponseAlpha->assertSee('Alerte Alpha Unique', false);
        $reponseAlpha->assertDontSee('Alerte Beta', false);
    }

    public function test_rejeu_meme_transition_refuse_sans_doublon(): void
    {
        $incident = $this->incidentOuvert();

        $this->actingAs($this->admin)->post(
            route('admin.sentinel.incidents.transiter', $incident), ['cible' => 'resolu']
        )->assertRedirect();

        // Rejeu (double-clic) : transition resolu → resolu interdite.
        $this->actingAs($this->admin)->post(
            route('admin.sentinel.incidents.transiter', $incident), ['cible' => 'resolu']
        )->assertStatus(422);

        $this->assertSame('resolu', $incident->fresh()->statut);
        $this->assertSame(1, SentinelIncident::where('cle_unique', $incident->cle_unique)->count());
    }

    public function test_transitions_conflictuelles_sequentielles_dernier_gagne_coherent(): void
    {
        $incident = $this->incidentOuvert();

        $this->actingAs($this->admin)->post(
            route('admin.sentinel.incidents.transiter', $incident), ['cible' => 'acquitte']
        )->assertRedirect();

        $this->actingAs($this->admin)->post(
            route('admin.sentinel.incidents.transiter', $incident), ['cible' => 'resolu']
        )->assertRedirect();

        $frais = $incident->fresh();
        $this->assertSame('resolu', $frais->statut);
        $this->assertNotNull($frais->resolu_le);
    }
}
