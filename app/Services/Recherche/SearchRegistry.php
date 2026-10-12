<?php

namespace App\Services\Recherche;

use App\Models\User;
use App\Support\Modules\ModuleRegistry;

/**
 * Registre de recherche (Phase 7 §7.2) : point unique d'extension.
 * La page globale et l'instantanée consomment les mêmes providers
 * (mêmes gates, mêmes périmètres) — aucune requête ad hoc ailleurs.
 */
class SearchRegistry
{
    /** @var array<int, SearchProvider> */
    private array $providers = [];

    public function __construct(private readonly ModuleRegistry $modules) {}

    public function enregistrer(SearchProvider $provider): void
    {
        $this->providers[] = $provider;
    }

    public function moduleActif(string $module): bool
    {
        return $this->modules->estActif($module);
    }

    /**
     * @return array<string, array{route: ?string, resultats: array<int, array{titre: string, detail: string, lien: ?string}>}>
     */
    public function sections(User $utilisateur, string $motif, int $limite = 6): array
    {
        $sections = [];

        foreach ($this->providers as $provider) {
            if (! $provider->eligible($utilisateur)) {
                continue;
            }

            $sections[$provider->section()] = [
                'route' => $provider->routeListe(),
                'resultats' => $provider->rechercher($utilisateur, $motif, $limite),
            ];
        }

        return $sections;
    }

    /**
     * @return array<int, array{section: string, titre: string, detail: string, lien: ?string}>
     */
    public function instantanee(User $utilisateur, string $motif, int $limite = 5): array
    {
        $resultats = [];

        foreach ($this->providers as $provider) {
            if (! $provider->eligible($utilisateur)) {
                continue;
            }

            foreach ($provider->rechercher($utilisateur, $motif, $limite) as $item) {
                $resultats[] = ['section' => $provider->section()] + $item;
            }
        }

        return $resultats;
    }
}
