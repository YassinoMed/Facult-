<?php

namespace App\Jobs;

use App\Models\DatabaseBackup;
use App\Services\Sauvegardes\TestRestaurationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Test de restauration en file (LOOP BACKUP-01 dette 3) :
 * orchestre `TestRestaurationService`, jamais sur la base live.
 */
class TesterRestaurationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(public readonly int $backupId, public readonly ?string $connexion = null)
    {
        $this->onQueue('default');
    }

    public function handle(TestRestaurationService $service): void
    {
        $ligne = $this->connexion !== null
            ? DatabaseBackup::on($this->connexion)->find($this->backupId)
            : DatabaseBackup::find($this->backupId);

        if (! $ligne instanceof DatabaseBackup) {
            return;
        }

        $service->tester($ligne);
    }
}
