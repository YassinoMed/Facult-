<?php

namespace App\Modules\Sentinel\Jobs;

use App\Modules\Sentinel\Services\SentinelService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Platform Sentinel — exécution asynchrone d'une ronde de surveillance.
 */
class ExecuterRondeSentinelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly ?string $code = null,
    ) {}

    public function handle(SentinelService $sentinel): void
    {
        $sentinel->executer($this->code);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['sentinel', 'ronde', $this->code ?? 'tous'];
    }
}
