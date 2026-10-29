<?php

namespace App\Modules\Evaluation\Events;

use Illuminate\Foundation\Events\Dispatchable;

class EvaluationChanged
{
    use Dispatchable;

    /**
     * Marqueur de convention projet (cf. StudentRegistered,
     * PeriodeInscriptionOuverte) : les effets sensibles dépendants de ce
     * type d'événement — en particulier la notification de publication —
     * sont émis hors transaction (DeliberationService::publier), jamais
     * pour une écriture qui pourrait être annulée.
     */
    public bool $afterCommit = true;

    public function __construct(
        public readonly string $typeChangement,
        public readonly int $entiteId,
    ) {}
}
