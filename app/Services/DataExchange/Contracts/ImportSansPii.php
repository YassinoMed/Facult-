<?php

namespace App\Services\DataExchange\Contracts;

/**
 * Extension optionnelle fail-closed : en-têtes brutes INTERDITES dans
 * le fichier (ex. identité dans un flux anonyme). Le moteur rejette
 * le fichier (PII_FIELD_FORBIDDEN) au lieu d'ignorer silencieusement
 * la colonne. Comparaison sur en-têtes normalisées (même forme que
 * les alias). Opt-in par définition (certaines entités, ex. étudiants,
 * portent légitimement ces colonnes).
 */
interface ImportSansPii
{
    /**
     * @return array<int, string>
     */
    public function colonnesInterdites(): array;
}
