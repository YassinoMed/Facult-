<?php

namespace App\Events;

use App\Models\RegistrationPeriod;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Événement déclenché lors de l'ouverture officielle d'une période d'inscription (LOOP 12).
 */
class PeriodeInscriptionOuverte
{
    use Dispatchable;

    public $afterCommit = true;

    public function __construct(
        public readonly RegistrationPeriod $periode,
    ) {}
}
