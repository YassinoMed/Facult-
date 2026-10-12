<?php

namespace App\Modules\StudentDossiers\Events;

use App\Modules\StudentDossiers\Models\StudentDocument;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Document expiré (§10) : point d'extension pour un futur job de
 * balayage (expires_at dépassé → statut `expired`). Aucun traitement
 * automatique branché à ce stade.
 */
class DocumentExpired
{
    use Dispatchable;

    public function __construct(public readonly StudentDocument $document) {}
}
