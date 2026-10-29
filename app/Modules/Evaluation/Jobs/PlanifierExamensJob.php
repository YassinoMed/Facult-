<?php

namespace App\Modules\Evaluation\Jobs;

use App\Modules\Evaluation\Models\Examen;
use App\Modules\Schedule\Services\ExamScheduleEngineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Job asynchrone d'ordonnancement et de planification automatique des examens (LOT 06).
 * Exécute la résolution CSP sous contraintes dures et préférences souples en tâche de fond
 * pour les sessions volumineuses avec isolation et verrou anti-chevauchement.
 */
class PlanifierExamensJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly int $examenId,
        public readonly ?string $scenarioId = null,
        public readonly array $options = [],
        public readonly ?int $userId = null,
        public readonly bool $appliquerDirectement = false,
    ) {
        $this->onQueue('default');
    }

    /**
     * Verrouillage anti-chevauchement par examen.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('exam_planifier:'.$this->examenId))->expireAfter(1200),
        ];
    }

    /**
     * Exécute la génération ou l'application du planning.
     *
     * @return array<string, mixed>
     */
    public function handle(ExamScheduleEngineService $engine): array
    {
        $examen = Examen::findOrFail($this->examenId);

        $resultat = $engine->genererScenariosPlanning($examen, $this->options);

        if ($this->appliquerDirectement && $this->scenarioId && isset($resultat['scenarios'][$this->scenarioId])) {
            $scenarioChoisi = $resultat['scenarios'][$this->scenarioId];

            return $engine->appliquerScenario(
                $examen,
                $this->scenarioId,
                $scenarioChoisi,
                $this->userId,
                $this->options['autoriser_ecrasement'] ?? false
            );
        }

        return $resultat;
    }
}
