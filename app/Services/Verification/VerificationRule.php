<?php

namespace App\Services\Verification;

use App\Models\Etudiant;

/**
 * Contrat d'une règle de vérification du dossier étudiant.
 *
 * Chaque implémentation détecte un type d'anomalie précis,
 * retourne zéro ou plusieurs anomalies, et ne modifie JAMAIS
 * les données — lecture seule, assistante, jamais corrective.
 */
interface VerificationRule
{
    /** Identifiant unique de la règle (ex. 'identite.complete'). */
    public function code(): string;

    /** Libellé lisible affiché dans le cockpit (ex. 'Identité complète'). */
    public function libelle(): string;

    /** Catégorie métier : identite|inscription|parcours|document|doublon|chronologie|affectation|orphelin */
    public function categorie(): string;

    /**
     * Exécuter la vérification pour un étudiant.
     *
     * @return array<int, Anomalie> Liste des anomalies détectées (vide = OK).
     */
    public function verifier(Etudiant $etudiant): array;
}
