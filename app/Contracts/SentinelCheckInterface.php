<?php

namespace App\Contracts;

/**
 * Platform Sentinel — contrat d'un contrôle enregistrable. Le module
 * reste indépendant des détails internes des modules surveillés : chaque
 * contrôle lit via les services métier existants (lecture seule) et
 * retourne des constats structurés. Jamais d'écriture métier ici.
 */
interface SentinelCheckInterface
{
    /** Code stable du contrôle (ex. `INFRA_BASE_DONNEES`). */
    public function code(): string;

    /** Catégorie (ex. `infrastructure`, `integrite`, `configuration`). */
    public function categorie(): string;

    /** Gravité par défaut d'un constat (`info`, `avertissement`, `erreur`, `critique`). */
    public function gravite(): string;

    /** Module surveillé (`infrastructure`, `affectations`, …) ou null si transverse. */
    public function module(): ?string;

    /**
     * Exécute le contrôle (lecture seule, borné, jamais bloquant pour
     * l'appelant qui isole chaque contrôle en try/catch).
     *
     * @return array<int, array{code?: string, gravite?: string, module?: ?string, titre: string, diagnostic: string, recommandation?: ?string, entite_type?: ?string, entite_id?: ?int, entite_libelle?: ?string, faculte_id?: ?int}>
     */
    public function controler(): array;
}
