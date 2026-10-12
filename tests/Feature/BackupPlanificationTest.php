<?php

namespace Tests\Feature;

use App\Jobs\ExecuterSauvegardeJob;
use App\Models\DatabaseBackup;
use App\Models\Parametre;
use App\Models\User;
use App\Services\BackupService;
use App\Services\Sauvegardes\BackupRetentionService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Évaluateur de planning + rétention : déclenchement idempotent,
 * reprise des RUNNING périmés, destinations, rétention jours+nombre.
 */
class BackupPlanificationTest extends TestCase
{
    use RefreshDatabase;

    private function parametre(string $cle, string $valeur, string $type = 'texte'): void
    {
        Parametre::updateOrCreate(['cle' => $cle], [
            'valeur' => $valeur, 'type' => $type,
            'categorie' => 'sauvegardes', 'description' => 'Test.',
        ]);
        Cache::forget("parametre.{$cle}");
    }

    private function running(?string $debut = null): DatabaseBackup
    {
        return DatabaseBackup::create([
            'uuid' => (string) Str::uuid(),
            'filename' => 'planifie-test.sql',
            'disk' => 'local',
            'path' => sys_get_temp_dir().'/planifie-test.sql',
            'database_name' => 'test',
            'connexion' => 'mysql',
            'type' => 'SCHEDULED',
            'status' => 'RUNNING',
            'started_at' => $debut ?? now()->toDateTimeString(),
        ]);
    }

    public function test_evaluateur_declenche_si_echu(): void
    {
        Queue::fake();

        $this->artisan('sauvegarde:evaluer')->assertSuccessful();

        Queue::assertPushed(ExecuterSauvegardeJob::class, 1);
        $this->assertSame(1, DatabaseBackup::where('status', 'RUNNING')->where('type', 'SCHEDULED')->count());
    }

    public function test_evaluateur_ne_fait_rien_si_desactive(): void
    {
        $this->parametre('sauvegarde.plan.actif', '0', 'booleen');
        Queue::fake();

        $this->artisan('sauvegarde:evaluer')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame(0, DatabaseBackup::count());
    }

    public function test_evaluateur_evite_le_doublon_si_running(): void
    {
        $this->running();
        Queue::fake();

        $this->artisan('sauvegarde:evaluer')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame(1, DatabaseBackup::where('status', 'RUNNING')->count());
    }

    public function test_evaluateur_reprend_running_perime(): void
    {
        $ancien = $this->running(now()->subHours(3)->toDateTimeString());
        Queue::fake();

        $this->artisan('sauvegarde:evaluer')->assertSuccessful();

        $this->assertSame('FAILED', $ancien->fresh()->status);
        $this->assertSame('STALE_RUNNING : worker sans signe de vie.', $ancien->fresh()->error_code);
        Queue::assertPushed(ExecuterSauvegardeJob::class, 1);
        $this->assertSame(1, DatabaseBackup::where('status', 'RUNNING')->count());
    }

    public function test_destination_inconnue_replie_local(): void
    {
        $this->parametre('sauvegarde.plan.destination', 'atlantide');
        Queue::fake();

        $this->artisan('sauvegarde:evaluer')->assertSuccessful();

        $ligne = DatabaseBackup::where('status', 'RUNNING')->first();
        $this->assertNotNull($ligne);
        $this->assertStringNotContainsString('atlantide', $ligne->path);
    }

    public function test_retention_jours_et_nombre(): void
    {
        $dossier = sys_get_temp_dir().'/retention-'.uniqid();
        mkdir($dossier, 0750, true);

        $vieux = $dossier.'/manifest-20200101-000000.json';
        file_put_contents($vieux, json_encode(['artefacts' => ['base' => ['fichier' => 'base-vieux.sql', 'sha256' => 'x']]]));
        file_put_contents($dossier.'/base-vieux.sql', 'vieux');
        touch($vieux, time() - 20 * 86400);
        touch($dossier.'/base-vieux.sql', time() - 20 * 86400);

        $recent = $dossier.'/manifest-'.now()->format('Ymd-His').'.json';
        file_put_contents($recent, json_encode(['artefacts' => ['base' => ['fichier' => 'base-recent.sql', 'sha256' => 'y']]]));
        file_put_contents($dossier.'/base-recent.sql', 'recent');

        DatabaseBackup::create([
            'uuid' => (string) Str::uuid(), 'filename' => 'base-vieux.sql', 'disk' => 'local',
            'path' => $dossier.'/base-vieux.sql', 'database_name' => 'test',
            'connexion' => 'mysql', 'status' => 'SUCCESS',
        ]);

        $resultat = app(BackupRetentionService::class)->purger($dossier, 10, 14);

        $this->assertSame(1, $resultat['supprimes']);
        $this->assertSame(1, $resultat['conserves']);
        $this->assertFileDoesNotExist($dossier.'/base-vieux.sql');
        $this->assertFileExists($dossier.'/base-recent.sql');
        $this->assertDatabaseMissing('database_backups', ['filename' => 'base-vieux.sql']);

        array_map('unlink', glob($dossier.'/*'));
        rmdir($dossier);
    }

    public function test_retention_ne_supprime_que_le_surplus(): void
    {
        $dossier = sys_get_temp_dir().'/retention2-'.uniqid();
        mkdir($dossier, 0750, true);

        foreach (['a', 'b', 'c'] as $i => $lettre) {
            file_put_contents($dossier."/manifest-2026010{$i}-000000.json", json_encode(['artefacts' => []]));
        }

        $resultat = app(BackupRetentionService::class)->purger($dossier, 10, null);

        $this->assertSame(['supprimes' => 0, 'conserves' => 3], $resultat);

        array_map('unlink', glob($dossier.'/*'));
        rmdir($dossier);
    }

    public function test_store_replie_sync_si_file_ko(): void
    {
        BackupService::fakeDumpRunner(function ($config, string $filepath) {
            file_put_contents($filepath, "-- dump\nCREATE TABLE t (id INT);\n");
        });

        $dir = sys_get_temp_dir().'/store-fallback-'.uniqid();
        mkdir($dir, 0750, true);
        config(['sauvegardes.dossier' => $dir]);

        $busKo = \Mockery::mock(Dispatcher::class);
        $busKo->shouldReceive('dispatchToQueue')->andThrow(new \RuntimeException('file KO'));
        app()->instance(Dispatcher::class, $busKo);

        $admin = User::creerCompteBackOffice([
            'name' => 'S', 'email' => 's@t.t', 'password' => 'Motdepasse@123',
        ], 'super_admin');

        $reponse = $this->actingAs($admin)->post(route('admin.system.backups.store'));

        $reponse->assertRedirect(route('admin.system.backups.index'));
        $reponse->assertSessionHas('success');
        $this->assertSame('SUCCESS', DatabaseBackup::latest('id')->first()->status);

        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }
}
