<?php

namespace Tests\Feature;

use App\Models\DatabaseBackup;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests unitaires et fonctionnels pour BackupService (LOOP BACKUP-01).
 *
 * Couvre :
 * - Création de backup avec format de nommage db_YYYY-MM-DD_HH-mm-ss.sql
 * - Stockage hors projet et blocage du path traversal / dossiers interdits
 * - Calcul et validation du checksum SHA-256
 * - Détection des échecs (mysqldump, fichier vide, fichier absent, altération)
 * - Journalisation de l'audit (backup.created, failed, verified, verification_failed)
 * - Permissions administrateur (view, create, verify)
 */
class BackupServiceTest extends TestCase
{
    use RefreshDatabase;

    private BackupService $service;

    private User $superAdmin;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(BackupService::class);
        $this->tempDir = sys_get_temp_dir().'/backup-test-'.uniqid();
        mkdir($this->tempDir, 0750, true);

        $this->superAdmin = User::creerCompteBackOffice([
            'name' => 'Super Admin',
            'email' => 'superadmin.test@universite.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin');
    }

    protected function tearDown(): void
    {
        BackupService::fakeDumpRunner(null);

        // Nettoyage du répertoire temporaire
        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    public function test_creation_backup_succes_avec_format_nom_et_checksum(): void
    {
        BackupService::fakeDumpRunner(function ($config, string $filepath) {
            file_put_contents($filepath, "-- DUMP TEST\nCREATE TABLE test (id INT);\nINSERT INTO test VALUES (1);\n");
        });

        $backup = $this->service->create(
            userId: $this->superAdmin->id,
            dossierCible: $this->tempDir
        );

        $this->assertInstanceOf(DatabaseBackup::class, $backup);
        $this->assertSame('SUCCESS', $backup->status);
        $this->assertMatchesRegularExpression('/^db_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}(\-\d+)?\.sql$/', $backup->filename);
        $this->assertFileExists($backup->path);
        $this->assertGreaterThan(0, $backup->size_bytes);
        $this->assertSame('sha256', $backup->checksum_algorithm);
        $this->assertSame(hash_file('sha256', $backup->path), $backup->checksum);
        $this->assertNotNull($backup->completed_at);
        $this->assertNotNull($backup->verified_at);

        // Vérification de l'audit système
        $this->assertDatabaseHas('integration_logs', [
            'integration' => 'sauvegarde',
            'operation' => 'backup.created',
            'status' => 'completed',
            'external_reference' => $backup->uuid,
        ]);
    }

    public function test_repertoire_interdit_et_path_traversal_rejetes(): void
    {
        // 1. Répertoire public
        $this->expectException(\InvalidArgumentException::class);
        $this->service->normaliserEtValiderDossier(public_path('sauvegardes'));
    }

    public function test_repertoire_storage_public_rejete(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->normaliserEtValiderDossier(storage_path('app/public/sauvegardes'));
    }

    public function test_path_traversal_vers_repertoire_interdit_rejete(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->normaliserEtValiderDossier(base_path('storage/../public'));
    }

    public function test_creation_echec_si_fichier_vide_et_audit_failed(): void
    {
        BackupService::fakeDumpRunner(function ($config, string $filepath) {
            // Génère un fichier de taille 0 octet
            touch($filepath);
        });

        try {
            $this->service->create(userId: $this->superAdmin->id, dossierCible: $this->tempDir);
            $this->fail('Une exception RuntimeException devait être levée pour un fichier vide.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('FICHIER_VIDE', $e->getMessage());
        }

        $backup = DatabaseBackup::latest('id')->first();
        $this->assertNotNull($backup);
        $this->assertSame('FAILED', $backup->status);

        $this->assertDatabaseHas('integration_logs', [
            'integration' => 'sauvegarde',
            'operation' => 'backup.failed',
            'status' => 'failed',
            'external_reference' => $backup->uuid,
        ]);
    }

    public function test_creation_echec_si_mysqldump_echoue_et_audit_failed(): void
    {
        BackupService::fakeDumpRunner(function ($config, string $filepath) {
            throw new \RuntimeException('Erreur processus mysqldump simulée.');
        });

        try {
            $this->service->create(userId: $this->superAdmin->id, dossierCible: $this->tempDir);
            $this->fail('Une exception devait être propagée.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Erreur processus mysqldump simulée', $e->getMessage());
        }

        $backup = DatabaseBackup::latest('id')->first();
        $this->assertSame('FAILED', $backup->status);
        $this->assertStringContainsString('Erreur processus mysqldump', (string) $backup->error_code);

        $this->assertDatabaseHas('integration_logs', [
            'integration' => 'sauvegarde',
            'operation' => 'backup.failed',
            'status' => 'failed',
            'external_reference' => $backup->uuid,
        ]);
    }

    public function test_verification_succes_si_checksum_valide(): void
    {
        BackupService::fakeDumpRunner(function ($config, string $filepath) {
            file_put_contents($filepath, "-- VALID CONTENT\n");
        });

        $backup = $this->service->create(userId: $this->superAdmin->id, dossierCible: $this->tempDir);

        $resultat = $this->service->verify($backup);

        $this->assertSame('VALID', $resultat['statut']);
        $this->assertSame($backup->checksum, $resultat['checksum']);
        $this->assertSame('SUCCESS', $backup->fresh()->status);

        $this->assertDatabaseHas('integration_logs', [
            'integration' => 'sauvegarde',
            'operation' => 'backup.verified',
            'status' => 'completed',
            'external_reference' => $backup->uuid,
        ]);
    }

    public function test_verification_echec_si_fichier_absent(): void
    {
        BackupService::fakeDumpRunner(function ($config, string $filepath) {
            file_put_contents($filepath, "-- VALID CONTENT\n");
        });

        $backup = $this->service->create(userId: $this->superAdmin->id, dossierCible: $this->tempDir);

        // Suppression manuelle du fichier physique
        @unlink($backup->path);

        $resultat = $this->service->verify($backup);

        $this->assertSame('INVALID', $resultat['statut']);
        $this->assertStringContainsString('FICHIER_ABSENT', $resultat['message']);
        $this->assertSame('VERIFICATION_FAILED', $backup->fresh()->status);

        $this->assertDatabaseHas('integration_logs', [
            'integration' => 'sauvegarde',
            'operation' => 'backup.verification_failed',
            'status' => 'failed',
            'external_reference' => $backup->uuid,
        ]);
    }

    public function test_verification_echec_si_checksum_altere(): void
    {
        BackupService::fakeDumpRunner(function ($config, string $filepath) {
            file_put_contents($filepath, "-- CONTENU ORIGINAL\n");
        });

        $backup = $this->service->create(userId: $this->superAdmin->id, dossierCible: $this->tempDir);

        // Altération physique du fichier sur le disque
        file_put_contents($backup->path, "-- CONTENU FALSIFIE\n");

        $resultat = $this->service->verify($backup);

        $this->assertSame('INVALID', $resultat['statut']);
        $this->assertStringContainsString('CHECKSUM_MISMATCH', $resultat['message']);
        $this->assertSame('VERIFICATION_FAILED', $backup->fresh()->status);

        $this->assertDatabaseHas('integration_logs', [
            'integration' => 'sauvegarde',
            'operation' => 'backup.verification_failed',
            'status' => 'failed',
            'external_reference' => $backup->uuid,
        ]);
    }

    public function test_admin_route_verifier_autorisee_pour_super_admin(): void
    {
        BackupService::fakeDumpRunner(function ($config, string $filepath) {
            file_put_contents($filepath, "-- VALID DATA\n");
        });

        $backup = $this->service->create(userId: $this->superAdmin->id, dossierCible: $this->tempDir);

        $response = $this->actingAs($this->superAdmin)
            ->post(route('admin.system.backups.verify', $backup));

        $response->assertRedirect(route('admin.system.backups.index'));
        $response->assertSessionHas('success');
    }

    public function test_admin_route_verifier_refusee_sans_permission(): void
    {
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent Sans Droit',
            'email' => 'agent.sansdroit@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        BackupService::fakeDumpRunner(function ($config, string $filepath) {
            file_put_contents($filepath, "-- VALID DATA\n");
        });

        $backup = $this->service->create(userId: $this->superAdmin->id, dossierCible: $this->tempDir);

        $response = $this->actingAs($agent)
            ->post(route('admin.system.backups.verify', $backup));

        $response->assertForbidden();
    }

    public function test_admin_route_creation_autorisee_et_cree_backup_avec_service(): void
    {
        BackupService::fakeDumpRunner(function ($config, string $filepath) {
            file_put_contents($filepath, "-- REAL DATA VIA WEB\n");
        });

        config(['sauvegardes.dossier' => $this->tempDir]);

        $response = $this->actingAs($this->superAdmin)
            ->post(route('admin.system.backups.store'));

        $response->assertRedirect(route('admin.system.backups.index'));
        $response->assertSessionHas('success');

        $backup = DatabaseBackup::latest('id')->first();
        $this->assertNotNull($backup);
        $this->assertSame('SUCCESS', $backup->status);
        $this->assertFileExists($backup->path);
    }
}
