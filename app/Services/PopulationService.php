<?php

namespace App\Services;

use App\Models\User;
use App\Services\Exports\ListeEtudiantsService;
use App\Services\Exports\PopulationDataset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Funnel population (§2) : TOUT dataset (cœur + modules) passe par
 * ListeEtudiantsService. Autorisation dataset + scope faculté imposés ici,
 * jamais côté module.
 */
class PopulationService
{
    public function __construct(private readonly ListeEtudiantsService $listes) {}

    /**
     * @param  array<string, mixed>  $contexte  scalaires uniquement (ids)
     */
    public function requete(string $dataset, array $contexte, ?User $utilisateur): Builder
    {
        $definition = PopulationDataset::definition($dataset);

        if ($utilisateur !== null) {
            Gate::forUser($utilisateur)->authorize($definition['permission']);
        }

        return $this->listes->requete(
            PopulationDataset::filtresPour($dataset, $contexte),
            $utilisateur,
        );
    }

    /**
     * @param  array<string, mixed>  $contexte
     * @return array{entetes: array<int, string>, lignes: array<int, array<string, mixed>>}
     */
    public function jeuDonnees(string $dataset, array $contexte, ?User $utilisateur): array
    {
        return $this->listes->jeuDonnees(
            $this->requete($dataset, $contexte, $utilisateur),
            $utilisateur,
            PopulationDataset::colonnesPour($dataset, $contexte),
        );
    }

    /**
     * @param  array<string, mixed>  $contexte
     * @return array{entetes: array<int, string>, total: int, sections: array<int, array{titre: string, effectif: int, lignes: array<int, array<string, mixed>>}>}|null
     */
    public function sections(string $dataset, array $contexte, ?User $utilisateur): ?array
    {
        $regroupement = PopulationDataset::regroupementPour($dataset, $contexte);

        if ($regroupement === null) {
            return null;
        }

        return $this->listes->sectionsRegroupees(
            $this->requete($dataset, $contexte, $utilisateur),
            $utilisateur,
            PopulationDataset::colonnesPour($dataset, $contexte),
            $regroupement,
        );
    }
}
