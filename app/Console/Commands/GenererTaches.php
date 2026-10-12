<?php

namespace App\Console\Commands;

use App\Modules\Tasks\Services\TaskGenerationService;
use Illuminate\Console\Command;

/**
 * Génération quotidienne des tâches (§9.15) : idempotente —
 * relançable sans créer de doublons (clé source unique).
 */
class GenererTaches extends Command
{
    protected $signature = 'taches:generer';

    protected $description = 'Génère les tâches administratives depuis les émetteurs (SLA, justifications, délibérations, épreuves).';

    public function handle(TaskGenerationService $generation): int
    {
        $compteurs = $generation->generer();

        $this->info('Tâches : '.json_encode($compteurs, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
