<?php

namespace App\Events;

use App\Models\Etudiant;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Événement déclenché lors d'une décision administrative sur le dossier (LOOP 12) :
 * - Validation définitive de l'inscription ;
 * - Rejet officiel de la candidature.
 */
class DossierDecisionPrise
{
    use Dispatchable;

    public $afterCommit = true;

    public function __construct(
        public readonly Etudiant $etudiant,
        public readonly string $statut,
        public readonly ?string $motif = null,
        public readonly ?int $auteurId = null,
    ) {}
}
