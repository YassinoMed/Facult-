<?php

namespace App\Modules\Referentiel\Events;

use App\Modules\Referentiel\Models\Maquette;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Transition de maquette (audit §28 : sans PII, ids + statut).
 */
class MaquetteTransitionnee
{
    use Dispatchable;

    public function __construct(
        public readonly Maquette $maquette,
        public readonly string $transition,
        public readonly ?int $auteurId,
    ) {}
}
