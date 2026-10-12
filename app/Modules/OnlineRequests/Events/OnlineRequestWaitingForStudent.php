<?php

namespace App\Modules\OnlineRequests\Events;

use App\Modules\OnlineRequests\Models\OnlineRequest;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pièce attendue : déclenche la notification étudiante (retour en
 * examen après dépôt géré par ailleurs).
 */
class OnlineRequestWaitingForStudent
{
    use Dispatchable;

    public function __construct(public readonly OnlineRequest $demande, public readonly string $commentaire) {}
}
