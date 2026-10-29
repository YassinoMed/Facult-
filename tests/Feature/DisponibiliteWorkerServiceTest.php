<?php

namespace Tests\Feature;

use App\Services\DisponibiliteWorkerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tests du service DisponibiliteWorkerService (§52).
 * Vérifie la détection dynamique des workers et le repli synchrone automatique.
 */
class DisponibiliteWorkerServiceTest extends TestCase
{
    private DisponibiliteWorkerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DisponibiliteWorkerService;
        Cache::forget(DisponibiliteWorkerService::CLE_CACHE_BATTEMENT);
    }

    public function test_sous_le_seuil_reste_toujours_synchrone(): void
    {
        Config::set('integrations.queue_threshold', 500);

        // 300 éléments < 500 => synchrone (false), qu'un worker tourne ou non.
        $this->assertFalse($this->service->doitBasculerEnFile(300));
        $this->assertFalse($this->service->doitBasculerEnFile(500));
    }

    public function test_fallback_synchrone_si_aucun_worker_actif(): void
    {
        Config::set('queue.default', 'database');
        Config::set('integrations.queue_threshold', 500);
        Config::set('integrations.auto_fallback_synchrone', true);

        // Aucun battement en cache et queue = database
        // Volume 600 > 500 => doit basculer en synchrone automatique (false) au lieu de bloquer.
        $this->assertFalse($this->service->doitBasculerEnFile(600));
    }

    public function test_bascule_en_file_si_worker_actif_avec_battement(): void
    {
        Config::set('queue.default', 'database');
        Config::set('integrations.queue_threshold', 500);

        // Enregistrement d'un battement de worker en cache
        $this->service->enregistrerBattement();

        $this->assertTrue($this->service->workerActif());
        // Volume 600 > 500 avec worker actif => mise en file d'attente normale (true).
        $this->assertTrue($this->service->doitBasculerEnFile(600));
    }

    public function test_battement_expire_declare_le_worker_inactif(): void
    {
        Config::set('queue.default', 'database');

        // Battement antérieur à 60 secondes (périmé)
        Cache::put(
            DisponibiliteWorkerService::CLE_CACHE_BATTEMENT,
            now()->timestamp - 60,
            DisponibiliteWorkerService::TTL_BATTEMENT_SECONDES
        );

        $this->assertFalse($this->service->workerActif());
    }

    public function test_desactivation_du_repli_automatique(): void
    {
        Config::set('queue.default', 'database');
        Config::set('integrations.queue_threshold', 500);
        Config::set('integrations.auto_fallback_synchrone', false);

        // Repli désactivé explicitement => bascule en file même sans worker
        $this->assertTrue($this->service->doitBasculerEnFile(600));
    }

    public function test_queue_sync_toujours_consideree_active(): void
    {
        Config::set('queue.default', 'sync');

        $this->assertTrue($this->service->workerActif());
    }

    public function test_queue_fake_preserve_la_mise_en_file(): void
    {
        Config::set('queue.default', 'database');
        Queue::fake();

        // En présence de Queue::fake(), le worker est considéré actif pour permettre les tests Queue::assertPushed
        $this->assertTrue($this->service->workerActif());
        $this->assertTrue($this->service->doitBasculerEnFile(600, 500));
    }
}
