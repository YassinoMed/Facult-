<?php

namespace App\Modules\Evaluation\Listeners;

use App\Models\ImportReport;
use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\StudentDossiers\Jobs\GenerateOfficialDocumentsJob;
use Throwable;

/**
 * Génération automatique des relevés à la publication (LOOP 4 §22) :
 * relevé annuel de chaque décision publiée, via le job existant en
 * file (jamais en HTTP). Désactivable via
 * `documents-officiels.generation_auto_publication`. Catch-all : une
 * panne de génération ne doit jamais annuler la publication.
 */
class GenererRelevesPublication
{
    public function handle(EvaluationChanged $event): void
    {
        try {
            if ($event->typeChangement !== 'deliberation.publiee') {
                return;
            }

            if (! (bool) config('documents-officiels.generation_auto_publication', true)) {
                return;
            }

            $rapport = ImportReport::create([
                'user_id' => null,
                'integration' => 'systeme',
                'direction' => 'import',
                'type' => 'data.releves',
                'format' => 'pdf',
                'filename' => "releves-auto-deliberation-{$event->entiteId}",
                'status' => 'pending',
                'mode' => 'atomique',
            ]);

            GenerateOfficialDocumentsJob::dispatch($rapport->id, $event->entiteId, 'releve_notes', 0);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
