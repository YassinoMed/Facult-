<?php

namespace App\Jobs\Io;

use App\Models\ImportReport;
use App\Services\Io\ExportOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Export asynchrone des étudiants (§52).
 */
class ExportStudentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(public readonly ImportReport $rapport) {}

    public function handle(ExportOrchestrator $orchestrator): void
    {
        $orchestrator->executer($this->rapport, $this->rapport->user);
    }
}
