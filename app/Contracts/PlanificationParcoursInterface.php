<?php

namespace App\Contracts;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;

/**
 * Planification du parcours : analyse l'existant et propose
 * un parcours possible, sans jamais écrire de décision officielle.
 */
interface PlanificationParcoursInterface
{
    /**
     * @return array{etudiant_id: int, inscription_source_id: ?int, niveau_actuel: ?string, programme: ?string, credits_obtenus: int, credits_requis: int, credits_manquants: int, validees: array, dettes: array, decisions: array, amenagements: array, propositions: array, regles: array}
     */
    public function analyser(Etudiant $etudiant, ?InscriptionAcademique $source = null): array;
}
