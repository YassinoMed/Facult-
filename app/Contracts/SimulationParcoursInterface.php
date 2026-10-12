<?php

namespace App\Contracts;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\ParcoursAcademique\Models\ParcoursSimulation;

/**
 * Simulation de parcours : hypothèses réversibles, résultat
 * explicable, persistance append-only, zéro écriture métier.
 */
interface SimulationParcoursInterface
{
    public function simuler(
        Etudiant $etudiant,
        string $scenario,
        array $hypotheses = [],
        ?InscriptionAcademique $source = null,
        ?int $auteurId = null,
    ): ParcoursSimulation;
}
