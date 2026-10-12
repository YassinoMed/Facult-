<?php

namespace App\Services\DataExchange\Contracts;

/**
 * Extension optionnelle d'import : alias d'en-têtes (fichiers dont les
 * colonnes ont d'autres noms) + validation métier par ligne à la
 * preview (résolution, cohérence, verrous) avec codes d'erreur
 * structurés. Le moteur les appelle s'ils existent (100 % rétrocompatible).
 */
interface ImportValidationAvancee
{
    /**
     * Alias acceptés par colonne canonique (comparaison insensible à
     * la casse et aux espaces). Ex. ['cin' => ['c.i.n', 'n°cin']].
     *
     * @return array<string, array<int, string>>
     */
    public function aliasesColonnes(): array;

    /**
     * Contrôles métier d'une ligne déjà valide (règles de base OK).
     * Retourne des erreurs bloquantes structurées (jamais d'exception :
     * une ligne en échec ne doit pas avorter toute la preview).
     *
     * @param  array<string, mixed>  $ligne  Ligne normalisée (+ `ligne` = n°).
     * @return array<int, array{champ: string, message: string, code: string}>
     */
    public function validerLigneMetier(array $ligne, ?int $auteurId): array;

    /**
     * Clé métier résolue (ex. `etudiant:12|matiere:45|sem:3|2026-2027|normale`)
     * pour détecter les doublons intra-fichier même via des identifiants
     * différents. Null si non résoluble (erreur déjà signalée).
     *
     * @param  array<string, mixed>  $ligne
     */
    public function cleMetier(array $ligne): ?string;
}
