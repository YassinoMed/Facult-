<?php

namespace Tests\Feature;

use App\Models\AnomalieQualite;
use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\IntegrationLog;
use App\Models\User;
use App\Modules\Tasks\Models\Task;
use App\Services\AgregateurQualite;
use App\Services\AuditService;
use App\Services\Io\StudentsImporter;
use App\Services\NormalisationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Phases 19-21 — Qualité, API, supervision : normalisation,
 * agrégation + persistance anomalies, enveloppe erreur, OpenAPI,
 * readiness, correlation ID, alerting ops.
 */
class QualiteApiSupervisionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.qas@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    public function test_normalisation_formes_canoniques(): void
    {
        $service = app(NormalisationService::class);

        $this->assertSame('sara@test.ma', $service->email('  Sara@Test.MA '));
        $this->assertSame('+21698765432', $service->telephone('98 765 432'));
        $this->assertSame('+21698765432', $service->telephone('+216 98 765 432'));
        $this->assertSame('AB123456', $service->cin(' ab 123456 '));
        $this->assertSame('El Idrissi', $service->nomPropre('  EL   IDRISSI '));
        $this->assertNull($service->email(''));
    }

    public function test_import_normalise_telephone(): void
    {
        $apercu = app(StudentsImporter::class)->valider([[
            'cin' => 'QAS00001',
            'nom' => 'Benali',
            'prenom' => 'Sara',
            'email' => 'Sara.QAS@test.ma',
            'telephone' => '22 333 444',
            'faculte' => 'FST',
            'date_naissance' => '2004-01-01',
            'sexe' => 'F',
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'X',
            'ville' => 'Casa',
            'code_postal' => '20000',
            'annee_bac' => '2024',
        ]]);

        $this->assertSame([], $apercu->erreurs);

        $resultat = app(StudentsImporter::class)->importer([[
            'cin' => 'QAS00001',
            'nom' => 'Benali',
            'prenom' => 'Sara',
            'email' => 'Sara.QAS@test.ma',
            'telephone' => '22 333 444',
            'faculte' => 'FST',
            'date_naissance' => '2004-01-01',
            'sexe' => 'F',
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'X',
            'ville' => 'Casa',
            'code_postal' => '20000',
            'annee_bac' => '2024',
        ]], 'partiel');

        $this->assertSame(1, $resultat['importes']);
        $this->assertSame('+21622333444', Etudiant::where('cin', 'QAS00001')->first()->telephone);
    }

    public function test_agregateur_detecte_persiste_et_resout(): void
    {
        $etudiant = Etudiant::create([
            'cin' => 'QAS00002', 'nom' => 'Sans', 'prenom' => 'Inscription', 'email' => 'sans.qas@test.ma',
            'date_naissance' => '2004-01-01', 'sexe' => 'M', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        $detectees = app(AgregateurQualite::class)->detecter();
        $this->assertNotEmpty($detectees);
        $this->assertArrayHasKey('cle_unique', $detectees[0]);

        $resultat = app(AgregateurQualite::class)->synchroniser();
        $this->assertGreaterThan(0, $resultat['creees']);

        // Idempotence : re-synchronisation sans doublon.
        $second = app(AgregateurQualite::class)->synchroniser();
        $this->assertSame(0, $second['creees']);

        // Cause corrigée → résolution automatique (`statut` non
        // mass-assignable : écriture forcée en test).
        $etudiant->forceFill(['statut' => 'en_attente'])->save();
        $troisieme = app(AgregateurQualite::class)->synchroniser();
        $this->assertGreaterThan(0, $troisieme['resolues']);
    }

    public function test_commande_qualite_cree_taches(): void
    {
        $etudiant = Etudiant::create([
            'cin' => 'QAS00003', 'nom' => 'Tache', 'prenom' => 'Auto', 'email' => 'tache.qas@test.ma',
            'date_naissance' => '2004-01-01', 'sexe' => 'M', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        $this->artisan('qualite:auditer --sync-tasks')->assertSuccessful();
        $this->assertDatabaseHas('tasks', ['source' => 'qualite']);

        // Relance : aucune tâche en double.
        $avant = Task::where('source', 'qualite')->count();
        $this->artisan('qualite:auditer --sync-tasks')->assertSuccessful();
        $this->assertSame($avant, Task::where('source', 'qualite')->count());
    }

    public function test_centre_qualite_permissions_et_transitions(): void
    {
        $this->actingAs($this->admin)->get(route('admin.qualite.index'))->assertOk();

        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.qas@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        // Lecture OK, transition refusée (moindre privilège).
        $this->actingAs($agent)->get(route('admin.qualite.index'))->assertOk();

        $anomalie = AnomalieQualite::create([
            'cle_unique' => 'test-1', 'source' => 'population', 'gravite' => 'avertissement',
            'message' => 'Test', 'statut' => 'ouverte',
        ]);

        $this->actingAs($agent)->post(route('admin.qualite.transiter', $anomalie), ['statut' => 'resolue'])
            ->assertForbidden();
        $this->actingAs($this->admin)->post(route('admin.qualite.transiter', $anomalie), ['statut' => 'resolue'])
            ->assertSessionHasNoErrors();
        $this->assertSame('resolue', $anomalie->fresh()->statut);
    }

    public function test_audit_trace_origine(): void
    {
        $etudiant = Etudiant::create([
            'cin' => 'QAS00004', 'nom' => 'Origine', 'prenom' => 'Test', 'email' => 'origine.qas@test.ma',
            'date_naissance' => '2004-01-01', 'sexe' => 'M', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);

        app(AuditService::class)->tracer($etudiant, 'test.action', 'dossier', null, null, null, 'import');

        $this->assertSame('import', HistoriqueAcademique::where('etudiant_id', $etudiant->id)->first()->source_systeme);
    }

    public function test_enveloppe_erreur_api_additive(): void
    {
        $reponse = $this->getJson('/api/v1/students');
        $reponse->assertStatus(401)->assertJson(['message' => 'Unauthenticated.']);
        $reponse->assertJsonStructure(['message', 'code', 'request_id']);
        $this->assertSame('UNAUTHENTICATED', $reponse->json('code'));
        $this->assertNotEmpty($reponse->headers->get('X-Request-ID'));
    }

    public function test_correlation_id_propage_et_valide(): void
    {
        $reponse = $this->getJson('/api/v1/health', ['X-Request-ID' => 'test-123']);
        $reponse->assertOk()->assertHeader('X-Request-ID', 'test-123');

        // ID invalide → UUID serveur, jamais d'erreur 500.
        $reponse = $this->getJson('/api/v1/health', ['X-Request-ID' => str_repeat('x', 200)]);
        $reponse->assertOk();
        $this->assertNotSame(str_repeat('x', 200), $reponse->headers->get('X-Request-ID'));
    }

    public function test_readiness_sans_pii(): void
    {
        $reponse = $this->getJson('/api/v1/ready');
        $reponse->assertJsonStructure(['status', 'sondes']);
        $noms = array_column($reponse->json('sondes'), 'nom');
        $this->assertContains('Base de données', $noms);
        $this->assertContains('Cache', $noms);
        $this->assertContains('File d’attente', $noms);
        $this->assertStringNotContainsString('password', strtolower($reponse->getContent()));
        // Environnement de test sain : base + cache OK.
        $parNom = array_column($reponse->json('sondes'), null, 'nom');
        $this->assertTrue($parNom['Base de données']['ok']);
        $this->assertTrue($parNom['Cache']['ok']);
    }

    public function test_openapi_couvre_routes(): void
    {
        $spec = Yaml::parseFile(base_path('docs/openapi.yaml'));
        $documentes = array_keys($spec['paths'] ?? []);

        $manquantes = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1/') || in_array($uri, ['api/v1/health', 'api/v1/ready'], true)) {
                continue;
            }

            if (! in_array('GET', $route->methods()) && ! in_array('POST', $route->methods())) {
                continue;
            }

            $normalise = preg_replace('#\{[^}]+\}#', '{x}', substr($uri, strlen('api/v1')));

            $trouve = false;
            foreach ($documentes as $chemin) {
                if (preg_replace('#\{[^}]+\}#', '{x}', $chemin) === $normalise) {
                    $trouve = true;
                    break;
                }
            }

            if (! $trouve) {
                $manquantes[] = $uri;
            }
        }

        $this->assertSame([], $manquantes);
    }

    public function test_api_routes_authentifiees_exigent_scope(): void
    {
        // Contrat Phase 11 §11.14 : toute route /api/v1 authentifiée
        // porte un middleware `scope:` explicite (sauf health/ready et
        // le webhook HMAC qui a son propre schéma d'authentification).
        $exemptees = ['api/v1/health', 'api/v1/ready', 'api/v1/webhooks/external-system', 'api/v1/version'];
        $sansScope = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1/') || in_array($uri, $exemptees, true)) {
                continue;
            }

            $middlewares = collect($route->gatherMiddleware())->map(
                fn ($m) => is_string($m) ? $m : (is_object($m) ? get_class($m) : '')
            );

            if (! $middlewares->contains(fn ($m) => str_starts_with((string) $m, 'scope:'))) {
                $sansScope[] = implode('|', $route->methods()).' '.$uri;
            }
        }

        $this->assertSame([], $sansScope);
    }

    public function test_supervision_alerte_dedup(): void
    {
        $superAdmin = User::creerCompteBackOffice([
            'name' => 'Super', 'email' => 'super.qas@universite.test', 'password' => 'Motdepasse@123',
        ], 'super_admin');

        for ($i = 0; $i < 6; $i++) {
            DB::table('failed_jobs')->insert([
                'uuid' => (string) Str::uuid(),
                'connection' => 'database',
                'queue' => 'default',
                'payload' => '{}',
                'exception' => 'Error',
                'failed_at' => now(),
            ]);
        }

        $this->artisan('supervision:controler')->assertSuccessful();
        $this->assertDatabaseHas('notifications', ['user_id' => $superAdmin->id, 'type' => 'supervision.alerte']);

        // Relance : cooldown anti-spam, aucune notification en double.
        $avant = AppNotification::where('type', 'supervision.alerte')->count();
        $this->artisan('supervision:controler')->assertSuccessful();
        $this->assertSame($avant, AppNotification::where('type', 'supervision.alerte')->count());
    }

    public function test_maintenance_et_purge_logs(): void
    {
        $superAdmin = User::creerCompteBackOffice([
            'name' => 'Super2', 'email' => 'super2.qas@universite.test', 'password' => 'Motdepasse@123',
        ], 'super_admin');

        $this->actingAs($superAdmin)->get(route('admin.maintenance.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.maintenance.index'))->assertForbidden();

        // Planificateur : remédiation affichée si heartbeat absent,
        // battement affiché si présent.
        $this->actingAs($superAdmin)->get(route('admin.maintenance.index'))
            ->assertOk()->assertSee('schedule:work', false);
        Cache::put('supervision:scheduler-dernier', now()->toDateTimeString(), 600);
        $this->actingAs($superAdmin)->get(route('admin.maintenance.index'))
            ->assertOk()->assertSee('dernier battement', false);

        IntegrationLog::create([
            'integration' => 'test', 'direction' => 'api', 'operation' => 'op',
            'status' => 'completed', 'created_at' => now()->subDays(100),
        ]);

        $this->artisan('maintenance:purger-logs')->assertSuccessful();
        $this->assertSame(0, IntegrationLog::where('integration', 'test')->count());
    }
}
