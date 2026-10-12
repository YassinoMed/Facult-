<?php

namespace App\Modules\ParcoursAcademique\Events;

use App\Models\Etudiant;

/**
 * Décision du parcours (équivalence, dispense, réorientation,
 * passerelle, plan) : audit sans PII, jamais de secret.
 */
class ParcoursDecisionEnregistree
{
    public function __construct(
        public readonly Etudiant $etudiant,
        public readonly string $categorie,
        public readonly int $entiteId,
        public readonly string $statut,
        public readonly ?int $auteurId = null,
    ) {}
}
