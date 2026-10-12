<?php

namespace App\Services\DataExchange\Contracts;

/**
 * Contrat opt-in (PHASE 5) : candidats à la similarité nominative pour
 * la détection de doublons POTENTIELS. Rétrocompatible (défaut : aucun
 * candidat). Ne fusionne JAMAIS : le moteur ne fait que proposer à la
 * revue. Requête BORNÉE obligatoire (jamais de scan complet).
 */
interface ImportSimilitude
{
    /**
     * @param  array<string, mixed>  $ligne  (clés canoniques)
     * @return array<int, array{id: int, nom: string, prenom: string, naissance: ?string}>
     */
    public function candidatsSimilaires(array $ligne, int $limite = 20): array;
}
