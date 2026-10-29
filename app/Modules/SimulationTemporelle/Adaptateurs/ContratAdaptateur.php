<?php

namespace App\Modules\SimulationTemporelle\Adaptateurs;

/**
 * Contrat d'adaptateur métier (ST-02) : LECTURE SEULE uniquement —
 * l'état initial agrégé provient des données réelles, sans jamais
 * copier de données personnelles (agrégats par niveau/cohorte).
 */
interface ContratAdaptateur
{
    /**
     * État initial agrégé pour un périmètre (faculté) et une année
     * source : ['cohortes' => [niveau_code => effectif], ...].
     */
    public function etatInitial(?int $faculteId, int $anneeSourceId, ?int $niveauId = null, ?array $groupesIds = null): array;
}
