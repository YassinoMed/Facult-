<?php

namespace App\Jobs\Io;

use App\Models\ImportReport;
use App\Services\Io\ImportOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Import asynchrone des notes (§52) — recalcul des moyennes inclus.
 */
class ImportGradesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(
        public readonly ImportReport $rapport,
        public readonly string $mode,
    ) {}

    public function handle(ImportOrchestrator $orchestrator): void
    {
        $orchestrator->executer($this->rapport, $this->mode);
    }
}
