<?php

namespace App\Modules\AssistantIa\Contracts;

use App\Models\User;
use App\Modules\AssistantIa\DTO\ResultatOutil;

/**
 * Contrat pour tout outil exposé à l'assistant IA (Function Calling).
 * Chaque outil vérifie ses permissions et son périmètre côté serveur.
 */
interface OutilIaInterface
{
    /**
     * Nom technique unique de l'outil (snake_case, ex: 'diagnostiquer_inscription').
     */
    public function nom(): string;

    /**
     * Description explicite pour le prompt et le modèle.
     */
    public function description(): string;

    /**
     * Mode d'utilisation : 'developpeur', 'metier', ou 'commun'.
     */
    public function mode(): string;

    /**
     * Permission RBAC minimale requise pour invoquer l'outil.
     */
    public function permissionRequise(): string;

    /**
     * Schéma JSON des paramètres acceptés par l'outil.
     *
     * @return array<string, mixed>
     */
    public function schemaParametres(): array;

    /**
     * Indique si l'outil produit ou propose une mutation sensible nécessitant approbation humaine.
     */
    public function estSensible(): bool;

    /**
     * Exécute l'outil avec les paramètres validés côté serveur pour l'utilisateur authentifié.
     *
     * @param  array<string, mixed>  $parametres
     */
    public function executer(array $parametres, User $utilisateur): ResultatOutil;
}
