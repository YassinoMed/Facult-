<?php

namespace App\Services\DataExchange\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Définition d'export par entité : requête scopée + colonnes
 * autorisées (jamais toutes les colonnes, jamais de secrets) +
 * formats supportés.
 */
interface ExportDefinitionInterface
{
    public function entite(): string;

    public function libelle(): string;

    /**
     * Colonnes autorisées à l'export (jamais toutes les colonnes de la
     * table, jamais de secrets). Liste ordonnée servant aussi d'en-têtes.
     *
     * @return array<int, string>
     */
    public function colonnes(): array;

    /**
     * @return array<int, string> formats parmi csv, xlsx, json, pdf
     */
    public function formats(): array;

    /**
     * Requête de base scopée (faculté + permission déjà vérifiées par
     * l'appelant via $request->user()).
     *
     * @return Builder
     */
    public function requete(Request $request);

    /**
     * Applique filtres actifs + sélection ids[] (export filtré/sélection).
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function filtrer($query, Request $request);

    /**
     * Projette un modèle vers une ligne de colonnes autorisées.
     *
     * @param  mixed  $modele
     * @return array<string, mixed>
     */
    public function ligne($modele): array;
}
