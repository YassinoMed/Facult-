<?php

namespace App\DataMesh\Contrats;

/**
 * Contrat standard d'un produit de données (Data Mesh logique).
 *
 * - Lecture seule : aucune méthode d'écriture (les mutations restent
 *   dans les services métier, transactions et politiques inchangées).
 * - Versionnage `MAJEUR.MINEUR` : MAJEUR = rupture de schéma, MINEUR =
 *   ajout compatible. Voir docs/data-mesh/CONTRACTS.md.
 * - Jamais de modèle Eloquent exposé tel quel : `donnees()` retourne
 *   des tableaux versionnés (DTO stables).
 */
interface ProduitDonnees
{
    /** Code stable du produit (ex. `inscriptions_validees`). */
    public function code(): string;

    /** Domaine propriétaire (ex. `scolarite`). */
    public function domaine(): string;

    /** Version du contrat (ex. `1.0`). */
    public function version(): string;

    /**
     * Descripteur complet : nom, description, finalité, propriétaire
     * métier, source de vérité, classification, fraîcheur attendue,
     * compatibilité, procédure d'erreur.
     *
     * @return array<string, mixed>
     */
    public function decrire(): array;

    /**
     * Schéma versionné : liste ordonnée de champs
     * [{champ, type, signification, sensible}].
     *
     * @return array<int, array{champ: string, type: string, signification: string, sensible: bool}>
     */
    public function schema(): array;

    /**
     * Lecture du produit. Contexte : ['utilisateur' => User requis,
     * 'faculte_id' => ?int, ...filtres documentés]. Lève
     * AuthorizationException si refusé. PII minimisée par construction.
     *
     * @return array<string, mixed>
     */
    public function donnees(array $contexte = []): array;

    /** Date ISO de dernière actualisation connue (source), null si inconnue. */
    public function fraicheur(): ?string;
}
