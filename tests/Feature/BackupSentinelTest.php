<?php

namespace Tests\Feature;

use App\Models\DatabaseBackup;
use App\Models\User;
use App\Modules\Sentinel\Models\SentinelIncident;
use App\Modules\Sentinel\Services\SentinelService;
use App\Services\ParametreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sauvegardes + Sentinel : planification admin, fraîcheur/échecs,
 * carte cockpit, attribution du pré-restore.
 */
class BackupSentinelTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::creerCompteBackOffice([
            'name' => 'Super', 'email' => 'super.sauvegarde@universite.test', 'password' => 'Motdepasse@123',
        ], 'super_admin');
    }

    private function succesIlYa(int $heures): DatabaseBackup
    {
        return DatabaseBackup::create([
            'uuid' => (string) Str::uuid(), 'filename' => 'db_test.sql', 'disk' => 'local',
            'path' => sys_get_temp_dir().'/db_test.sql', 'database_name' => 'test',
            'connexion' => 'mysql', 'status' => 'SUCCESS',
            'started_at' => now()->subHours($heures), 'completed_at' => now()->subHours($heures),
        ]);
    }

    public function test_planification_page_et_permission(): void
    {
        $this->actingAs($this->superAdmin)->get(route('admin.system.backups.planification'))
            ->assertOk()
            ->assertSee('Fréquence', false);

        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.sauvegarde@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->actingAs($agent)->get(route('admin.system.backups.planification'))->assertForbidden();
        $this->actingAs($agent)->post(route('admin.system.backups.planification.update'), [])->assertForbidden();
    }

    public function test_planification_enregistree_et_appliquee(): void
    {
        $reponse = $this->actingAs($this->superAdmin)->post(
            route('admin.system.backups.planification.update'),
            [
                'actif' => '1', 'frequence' => 'toutes_6h',
                'jours' => ['lundi', 'mardi'], 'heure' => '03:30',
                'retention_jours' => '30', 'conserver' => '20',
                'destination' => 'local_private', 'chiffrer' => '0',
                'alerter_echec' => '1', 'alerter_retard' => '1', 'alerter_succes' => '0',
            ]
        );

        $reponse->assertRedirect(route('admin.system.backups.planification'));
        $reponse->assertSessionHas('success');

        $service = app(ParametreService::class);
        $this->assertSame('toutes_6h', $service->get('sauvegarde.plan.frequence'));
        $this->assertSame(['lundi', 'mardi'], $service->get('sauvegarde.plan.jours'));
        $this->assertSame('03:30', $service->get('sauvegarde.plan.heure'));
        $this->assertSame(30, $service->get('sauvegarde.plan.retention_jours'));
        $this->assertSame(20, $service->get('sauvegarde.conserver'));
    }

    public function test_planification_invalide_refusee_sans_ecriture(): void
    {
        $avant = app(ParametreService::class)->get('sauvegarde.plan.frequence');

        $this->actingAs($this->superAdmin)->post(
            route('admin.system.backups.planification.update'),
            [
                'actif' => '1', 'frequence' => 'toutes_les_minutes',
                'heure' => '99:99', 'retention_jours' => '0', 'conserver' => '0',
                'destination' => 'atlantide', 'chiffrer' => '0',
                'alerter_echec' => '1', 'alerter_retard' => '1', 'alerter_succes' => '0',
            ]
        )->assertSessionHasErrors(['frequence', 'heure', 'retention_jours', 'conserver', 'destination']);

        $this->assertSame($avant, app(ParametreService::class)->get('sauvegarde.plan.frequence'));
    }

    public function test_fraicheur_sans_succes_puis_couverte(): void
    {
        app(SentinelService::class)->executer('SAUVEGARDE_FRAICHEUR');

        $incident = SentinelIncident::where('code', 'SAUVEGARDE_FRAICHEUR')->first();
        $this->assertNotNull($incident);
        $this->assertSame('critique', $incident->gravite);

        $this->succesIlYa(0);

        app(SentinelService::class)->executer('SAUVEGARDE_FRAICHEUR');

        $this->assertSame('resolu', $incident->fresh()->statut);
    }

    public function test_echecs_recents_agreges(): void
    {
        foreach ([5, 10] as $heures) {
            $ligne = $this->succesIlYa($heures);
            $ligne->update(['status' => 'FAILED']);
        }

        app(SentinelService::class)->executer('SAUVEGARDE_ECHECS');

        $incident = SentinelIncident::where('code', 'SAUVEGARDE_ECHECS')->first();
        $this->assertNotNull($incident);
        $this->assertSame('erreur', $incident->gravite);
        $this->assertStringContainsString('2 échec(s)', $incident->titre);
    }

    public function test_cockpit_sentinel_affiche_carte_sauvegardes(): void
    {
        $this->succesIlYa(0);

        config(['modules.sentinel.active' => true]);

        $this->actingAs($this->superAdmin)->get(route('admin.sentinel.index'))
            ->assertOk()
            ->assertSee('Sauvegardes SQL', false)
            ->assertSee('Prochaine exécution', false);
    }

    public function test_pre_restore_attribue_a_l_auteur(): void
    {
        $dossier = sys_get_temp_dir().'/sauvegarde-prerestore-'.uniqid();
        mkdir($dossier, 0750, true);
        $bd = $dossier.'/live.sqlite';
        touch($bd);

        config(['database.connections.restore_live' => [
            'driver' => 'sqlite', 'database' => $bd, 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        Artisan::call('migrate', ['--database' => 'restore_live', '--force' => true]);

        $this->assertSame(0, Artisan::call('sauvegarde:creer', [
            '--dossier' => $dossier, '--connexion' => 'restore_live',
            '--sans-documents' => true, '--conserver' => 3,
        ]));

        $manifests = glob($dossier.'/manifest-*.json') ?: [];
        $donnees = json_decode((string) file_get_contents($manifests[0]), true);
        $nomDump = basename((string) $donnees['artefacts']['base']['fichier']);

        $backup = DatabaseBackup::create([
            'uuid' => (string) Str::uuid(), 'filename' => $nomDump,
            'disk' => 'local', 'path' => $dossier.'/'.$nomDump, 'connexion' => 'restore_live',
            'database_name' => 'restore_live_db',
            'size_bytes' => filesize($dossier.'/'.$nomDump),
            'checksum' => $donnees['artefacts']['base']['sha256'], 'type' => 'MANUAL',
            'status' => 'SUCCESS', 'started_at' => now(), 'completed_at' => now(),
            'restore_tested_at' => now(), 'restore_test_status' => 'SUCCESS',
            'created_by' => $this->superAdmin->id,
        ]);

        Schema::connection('restore_live')->dropIfExists('database_backups');

        $this->actingAs($this->superAdmin)->post(route('admin.system.backups.restore', $backup), [
            'confirmation' => 'RESTORE restore_live_db',
        ])->assertRedirect(route('admin.system.backups.index'));

        $preRestore = DatabaseBackup::where('filename', 'like', 'pre-restore-%')->first();
        $this->assertNotNull($preRestore);
        $this->assertSame($this->superAdmin->id, (int) $preRestore->created_by);

        DB::purge('restore_live');

        foreach (glob($dossier.'/*') ?: [] as $fichier) {
            @unlink($fichier);
        }
        @rmdir($dossier);
    }
}
