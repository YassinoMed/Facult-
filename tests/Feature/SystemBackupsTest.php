<?php

namespace Tests\Feature;

use App\Jobs\ExecuterSauvegardeJob;
use App\Models\DatabaseBackup;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LOOP BACKUP-01 dette 1 — registre des sauvegardes + permissions :
 * table dédiée (jamais de contenu SQL), statuts métier, Gates
 * granulaires view ≠ create ≠ restore ≠ delete.
 */
class SystemBackupsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::creerCompteBackOffice([
            'name' => 'Super', 'email' => 'super.backups@universite.test', 'password' => 'Motdepasse@123',
        ], 'super_admin');
    }

    public function test_table_registre_existe_avec_colonnes_metier(): void
    {
        $this->assertTrue(Schema::hasTable('database_backups'));
        foreach (['uuid', 'filename', 'disk', 'path', 'database_name', 'size_bytes', 'checksum', 'checksum_algorithm', 'compression', 'status', 'type'] as $colonne) {
            $this->assertTrue(Schema::hasColumn('database_backups', $colonne), "Colonne manquante : {$colonne}");
        }
        $this->assertFalse(Schema::hasColumn('database_backups', 'contenu_sql'));
    }

    public function test_permissions_granulaires_declarees(): void
    {
        foreach (['system.backups.view', 'system.backups.create', 'system.backups.verify', 'system.backups.restore', 'system.backups.delete', 'system.backups.settings'] as $permission) {
            $this->assertContains($permission, Permissions::TOUTES);
        }
        // restore/delete/settings : super_admin seul (jamais auto-attribués).
        $this->assertContains('system.backups.restore', Permissions::pourRole('super_admin'));
        $this->assertNotContains('system.backups.restore', Permissions::pourRole('admin_scolarite'));
    }

    public function test_dashboard_reserve_au_perimetre(): void
    {
        $this->actingAs($this->superAdmin)->get(route('admin.system.backups.index'))->assertOk();

        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.backups@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->actingAs($agent)->get(route('admin.system.backups.index'))->assertForbidden();
    }

    // Note : l'exigence `restore → 403 sans permission dédiée` est
    // spécifiée et testée avec la dette 4 (restauration gardée).

    public function test_restauration_sans_permission_dediee_bloquee(): void
    {
        $scolarite = User::creerCompteBackOffice([
            'name' => 'Sco', 'email' => 'sco2.backups@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $backup = DatabaseBackup::create([
            'uuid' => (string) Str::uuid(), 'filename' => 'base-x.sql',
            'disk' => 'local', 'path' => sys_get_temp_dir().'/base-x.sql',
            'type' => 'MANUAL', 'status' => 'SUCCESS', 'started_at' => now(),
            'completed_at' => now(), 'created_by' => $this->superAdmin->id,
        ]);

        $this->actingAs($scolarite)->post(route('admin.system.backups.restore', $backup), [
            'confirmation' => 'RESTORE test',
        ])->assertForbidden();
    }

    public function test_restauration_confirmation_incorrecte_bloquee(): void
    {
        $dossier = sys_get_temp_dir().'/sauvegarde-restore-'.uniqid();
        mkdir($dossier, 0750, true);
        $bd = $dossier.'/live.sqlite';
        touch($bd);

        config(['database.connections.restore_live' => [
            'driver' => 'sqlite', 'database' => $bd, 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        Artisan::call('migrate', ['--database' => 'restore_live', '--force' => true]);
        $config = config('database.connections.restore_live');
        $config['database_name'] = 'restore_live_db';
        config(['database.connections.restore_live' => $config]);

        // Dump valide de cette base via le moteur existant.
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

        // Confirmation incorrecte : rien ne bouge.
        $this->actingAs($this->superAdmin)->post(route('admin.system.backups.restore', $backup), [
            'confirmation' => 'mauvaise valeur',
        ])->assertSessionHasErrors('confirmation');
        $this->assertSame('SUCCESS', $backup->fresh()->restore_test_status);

        // Checksum falsifié après enregistrement : BLOCK.
        $backup->update(['checksum' => hash('sha256', 'falsifié')]);
        $this->actingAs($this->superAdmin)->post(route('admin.system.backups.restore', $backup), [
            'confirmation' => 'RESTORE restore_live_db',
        ])->assertSessionHasErrors('backup');
        $this->assertSame(0, DatabaseBackup::where('type', 'MANUAL')->where('filename', 'like', 'pre-restore-%')->count());

        // Chemin nominal : checksum restauré + confirmation exacte.
        $backup->update(['checksum' => $donnees['artefacts']['base']['sha256']]);
        // Sabotage de la cible live : la restauration doit la remplacer.
        Schema::connection('restore_live')->dropIfExists('database_backups');

        $this->actingAs($this->superAdmin)->post(route('admin.system.backups.restore', $backup), [
            'confirmation' => 'RESTORE restore_live_db',
        ])->assertRedirect(route('admin.system.backups.index'));

        // Pré-restore créé AVANT (rollback disponible) + cible remplacée.
        $this->assertSame(1, DatabaseBackup::where('type', 'MANUAL')->where('filename', 'like', 'pre-restore-%')->count());
        // Reconnexion fraîche : le PDO du test pointe sinon vers
        // l'ancien inode (fichier remplacé par rename).
        DB::purge('restore_live');
        $this->assertTrue(
            Schema::connection('restore_live')->hasTable('database_backups')
        );

        foreach (glob($dossier.'/*') ?: [] as $fichier) {
            @unlink($fichier);
        }
        @rmdir($dossier);
    }

    public function test_restore_test_succes_et_base_temporaire_supprimee(): void
    {
        $dossier = sys_get_temp_dir().'/sauvegarde-rt-'.uniqid();
        mkdir($dossier, 0750, true);
        $bd = $dossier.'/test.sqlite';
        touch($bd);

        config(['database.connections.sauvegarde_rt' => [
            'driver' => 'sqlite', 'database' => $bd, 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        Artisan::call('migrate', ['--database' => 'sauvegarde_rt', '--force' => true]);
        $code = Artisan::call('sauvegarde:creer', [
            '--dossier' => $dossier, '--connexion' => 'sauvegarde_rt',
            '--sans-documents' => true, '--conserver' => 14,
        ]);
        $this->assertSame(0, $code);
        $manifests = glob($dossier.'/manifest-*.json') ?: [];
        $this->assertNotEmpty($manifests);
        $donnees = json_decode((string) file_get_contents($manifests[0]), true);
        $nomDump = basename((string) $donnees['artefacts']['base']['fichier']);

        $backup = DatabaseBackup::create([
            'uuid' => (string) Str::uuid(), 'filename' => $nomDump,
            'disk' => 'local', 'path' => $dossier.'/'.$nomDump, 'connexion' => 'sauvegarde_rt',
            'size_bytes' => filesize($dossier.'/'.$nomDump),
            'checksum' => $donnees['artefacts']['base']['sha256'], 'type' => 'MANUAL',
            'status' => 'SUCCESS', 'started_at' => now(), 'completed_at' => now(),
            'created_by' => $this->superAdmin->id,
        ]);

        $this->actingAs($this->superAdmin)->post(
            route('admin.system.backups.tester-restauration', $backup)
        )->assertRedirect(route('admin.system.backups.index'));

        $backup->refresh();
        $this->assertSame('SUCCESS', $backup->restore_test_status);
        $this->assertNotNull($backup->restore_tested_at);
        // Base temporaire supprimée : aucune trace du test.
        $this->assertSame([], glob($dossier.'/restore_test_*') ?: []);

        foreach (glob($dossier.'/*') ?: [] as $fichier) {
            @unlink($fichier);
        }
        @rmdir($dossier);
    }

    public function test_restore_test_dump_corrompu_failure_sans_import(): void
    {
        $dossier = sys_get_temp_dir().'/sauvegarde-rtc-'.uniqid();
        mkdir($dossier, 0750, true);
        $chemin = $dossier.'/base-faux.sql';
        file_put_contents($chemin, 'contenu valide');

        $backup = DatabaseBackup::create([
            'uuid' => (string) Str::uuid(), 'filename' => 'base-faux.sql',
            'disk' => 'local', 'path' => $chemin, 'size_bytes' => 15,
            'checksum' => hash('sha256', 'autre contenu'), 'type' => 'MANUAL',
            'status' => 'SUCCESS', 'started_at' => now(), 'completed_at' => now(),
            'created_by' => $this->superAdmin->id,
        ]);

        $this->actingAs($this->superAdmin)->post(
            route('admin.system.backups.tester-restauration', $backup)
        )->assertRedirect(route('admin.system.backups.index'));

        $backup->refresh();
        $this->assertSame('FAILURE', $backup->restore_test_status);

        foreach (glob($dossier.'/*') ?: [] as $fichier) {
            @unlink($fichier);
        }
        @rmdir($dossier);
    }

    public function test_creation_deleguee_au_moteur_existant(): void
    {
        Queue::fake();

        $this->actingAs($this->superAdmin)->post(route('admin.system.backups.store'))
            ->assertRedirect(route('admin.system.backups.index'));

        Queue::assertPushed(ExecuterSauvegardeJob::class);
        $this->assertDatabaseHas('database_backups', ['status' => 'RUNNING']);
    }

    public function test_double_declenchement_refuse_409(): void
    {
        DatabaseBackup::create([
            'uuid' => (string) Str::uuid(), 'filename' => 'x.sql',
            'disk' => 'local', 'path' => '/tmp/x.sql', 'status' => 'RUNNING', 'type' => 'MANUAL',
        ]);

        $this->actingAs($this->superAdmin)->post(route('admin.system.backups.store'))
            ->assertStatus(409);
    }

    public function test_creation_sans_permission_403(): void
    {
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent2.backups@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->actingAs($agent)->post(route('admin.system.backups.store'))->assertForbidden();
    }

    public function test_job_execute_dump_verifie_et_registre(): void
    {
        $dossier = sys_get_temp_dir().'/sauvegarde-job-'.uniqid();
        mkdir($dossier, 0750, true);
        $bd = $dossier.'/test.sqlite';
        touch($bd);

        config(['database.connections.sauvegarde_job' => [
            'driver' => 'sqlite', 'database' => $bd, 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // Schéma isolé : Job + dump sur vraie base fichier (jamais
        // :memory:, non sauvegardable). La ligne registre y vit aussi.
        Artisan::call('migrate', [
            '--database' => 'sauvegarde_job', '--force' => true,
        ]);

        $backup = DatabaseBackup::on('sauvegarde_job')->create([
            'uuid' => (string) Str::uuid(), 'filename' => 'en-cours.sql',
            'disk' => 'local', 'path' => $dossier.'/en-cours.sql', 'connexion' => 'sauvegarde_job',
            'type' => 'MANUAL', 'status' => 'RUNNING', 'started_at' => now(),
            'created_by' => null,
        ]);

        $job = new ExecuterSauvegardeJob($backup->id, 'sauvegarde_job');
        app()->call([$job, 'handle']);

        $backup = DatabaseBackup::on('sauvegarde_job')->findOrFail($backup->id);
        $this->assertSame('SUCCESS', $backup->status);
        $this->assertNotNull($backup->checksum);
        $this->assertNotNull($backup->verified_at);
        $this->assertFileExists($dossier.'/'.$backup->filename);

        foreach (glob($dossier.'/*') ?: [] as $fichier) {
            @unlink($fichier);
        }
        @rmdir($dossier);
    }

    public function test_job_echec_notifie_sans_fuite(): void
    {
        // Connexion inexploitable (:memory:) → FAILED propre + notif auteur.
        $backup = DatabaseBackup::create([
            'uuid' => (string) Str::uuid(), 'filename' => 'en-cours.sql',
            'disk' => 'local', 'path' => sys_get_temp_dir().'/en-cours-test.sql', 'connexion' => 'testing',
            'type' => 'MANUAL', 'status' => 'RUNNING', 'started_at' => now(),
            'created_by' => $this->superAdmin->id,
        ]);

        $job = new ExecuterSauvegardeJob($backup->id);

        try {
            app()->call([$job, 'handle']);
        } catch (\Throwable) {
            // Propagée au worker en prod (retry/dead-letter) ; ici ignorée.
        }

        $backup->refresh();
        $this->assertSame('FAILED', $backup->status);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->superAdmin->id, 'type' => 'sauvegarde.echec',
        ]);
    }
}
