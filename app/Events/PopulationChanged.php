<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fait population (§46) : entité + ids + valeurs, sans PII.
 */
class PopulationChanged
{
    use Dispatchable;

    public function __construct(
        public readonly string $domaine,
        public readonly string $action,
        public readonly int $entiteId,
        public readonly ?int $auteurId,
        public readonly ?array $contexte = null,
    ) {}
}
