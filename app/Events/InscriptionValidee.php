<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Inscription officiellement validée (LOOP affectation automatique) :
 * déclenchée APRÈS le commit de `InscriptionService::transiter()` vers
 * `valide`. Le listener tente l'affectation via le moteur existant —
 * jamais l'inverse (l'affectation ne bloque jamais la validation).
 */
class InscriptionValidee
{
    use Dispatchable;

    /**
     * Garantie transactionnelle : le listener ne s'exécute que si le
     * commit de la validation a réussi (ceinture et bretelles — le
     * dispatch est déjà placé après la transaction dans `transiter()`).
     */
    public $afterCommit = true;

    public function __construct(
        public readonly int $inscriptionId,
        public readonly int $etudiantId,
        public readonly int $anneeId,
        public readonly ?int $auteurId,
    ) {}
}
