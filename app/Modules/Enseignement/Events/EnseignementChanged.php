<?php

namespace App\Modules\Enseignement\Events;

use App\Modules\Enseignement\Models\Enseignement;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fait enseignement (audit §43 : ids + action, sans PII).
 */
class EnseignementChanged
{
    use Dispatchable;

    public function __construct(
        public readonly string $action,
        public readonly int $enseignementId,
        public readonly ?int $auteurId,
        public readonly ?array $contexte = null,
    ) {}
}
