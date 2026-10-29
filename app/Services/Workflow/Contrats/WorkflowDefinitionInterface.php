<?php

namespace App\Services\Workflow\Contrats;

use App\Models\User;

/**
 * Contrat d'une définition de workflow universitaire (§10-12).
 * Chaque définition est versionnée, explicite ses états, ses transitions permises,
 * ses préconditions (gardes), ses règles de séparation des fonctions (4 yeux)
 * et ses délais d'exécution applicables.
 */
interface WorkflowDefinitionInterface
{
    /**
     * Identifiant canonique du domaine de workflow (ex: 'inscription', 'correction', 'demande').
     */
    public function domaine(): string;

    /**
     * Numéro de version incrémental (ex: 1, 2).
     * Les dossiers en cours créés sous une version N doivent conserver cette version N.
     */
    public function version(): int;

    /**
     * Liste exhaustive des états possibles dans cette version.
     *
     * @return array<int, string>
     */
    public function etats(): array;

    /**
     * Matrice des transitions permises sous la forme :
     * ['etat_source' => ['etat_cible_1', 'etat_cible_2', ...]]
     *
     * @return array<string, array<int, string>>
     */
    public function transitions(): array;

    /**
     * Vérifie si la transition directe de $source vers $cible est autorisée par la topologie.
     */
    public function transitionAutorisee(string $source, string $cible): bool;

    /**
     * Vérifie les gardes métier spécifiques (ex: complétude des pièces, règles académiques).
     */
    public function verifierGardes(string $source, string $cible, mixed $entite, ?User $acteur): bool;

    /**
     * Indique si la transition vers $cible exige la séparation stricte des fonctions (quatre yeux).
     */
    public function requiertQuatreYeux(string $source, string $cible): bool;

    /**
     * Permission requise pour franchir la transition, ou null si libre.
     */
    public function permissionRequise(string $source, string $cible): ?string;

    /**
     * Délai maximum d'exécution en heures pour cette transition (SLA), ou null si non borné.
     */
    public function delaiMaxHeures(string $source, string $cible): ?int;
}
