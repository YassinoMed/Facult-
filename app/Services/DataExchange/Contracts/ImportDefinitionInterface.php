<?php

namespace App\Services\DataExchange\Contracts;

/**
 * Définition d'import par entité (§5) : le module déclare colonnes,
 * règles, identifiants et persistance — le moteur fait le reste
 * (parse, doublons, preview, transaction, rapport).
 */
interface ImportDefinitionInterface
{
    public function entite(): string;

    public function libelle(): string;

    /**
     * @return array<int, string>
     */
    public function colonnes(): array;

    /**
     * @return array<int, string>
     */
    public function colonnesRequises(): array;

    /**
     * Règles Laravel appliquées à chaque ligne normalisée.
     *
     * @return array<string, mixed>
     */
    public function regles(): array;

    /**
     * Clé d'unicité métier dans le fichier (ex. ['code']).
     *
     * @return array<int, string>
     */
    public function cleDoublon(): array;

    /**
     * Recherche d'un existant (code d'abord, jamais le nom seul si
     * un code existe) — null si nouveau.
     *
     * @param  array<string, mixed>  $ligne
     */
    public function trouverExistant(array $ligne): ?object;

    /**
     * Persiste UNE ligne validée (création ou mise à jour selon le
     * mode). Retourne un libellé pour le rapport.
     *
     * @param  array<string, mixed>  $ligne
     */
    public function persister(array $ligne, string $mode, ?int $auteurId): string;

    /**
     * Vide le référentiel avant un remplacement (jamais automatique :
     * appelé uniquement en mode `remplacer` confirmé).
     *
     * @return array<int, string>
     */
    public function modes(): array;

    public function vider(?int $auteurId): void;
}
