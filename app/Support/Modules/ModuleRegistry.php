<?php

namespace App\Support\Modules;

/**
 * Registre des modules (§3/§11/§14-15) — CORE stable, modules branchés.
 *
 * - l'activation est lue depuis config/modules.php ( backed par env,
 *   serveur uniquement) ;
 * - les routes d'un module désactivé répondent 404 via le middleware
 *   `module:nom` (ModuleServiceProvider) : appel direct impossible ;
 * - la navigation et les widgets sont filtrés ici sur l'activation,
 *   puis sur la permission en Blade (@can).
 */
final class ModuleRegistry
{
    /**
     * @return array<string, mixed>
     */
    private function declaration(string $module): array
    {
        /** @var array<string, mixed> $modules */
        $modules = config('modules', []);

        return $modules[$module] ?? [];
    }

    public function estActif(string $module): bool
    {
        return (bool) ($this->declaration($module)['active'] ?? false);
    }

    /**
     * @return array<int, string>
     */
    public function modulesActifs(): array
    {
        /** @var array<string, mixed> $modules */
        $modules = config('modules', []);

        return array_keys(array_filter(
            $modules,
            fn ($declaration) => is_array($declaration) && ($declaration['active'] ?? false) === true
        ));
    }

    /**
     * Entrées de navigation d'un espace, module actif uniquement.
     *
     * @return array<int, array{module: string, label: string, route: string, permission: ?string}>
     */
    public function elementsNavigation(string $espace): array
    {
        /** @var array<int, array{module: string, label: string, route: string, permission: ?string}> $entrees */
        $entrees = config("modules.nav.{$espace}", []);

        return array_values(array_filter(
            $entrees,
            fn (array $entree) => $this->estActif($entree['module'])
        ));
    }

    /**
     * Vues de widgets d'un espace, module actif uniquement.
     *
     * @return array<int, string>
     */
    public function widgets(string $espace): array
    {
        /** @var array<int, array{module: string, view: string}> $widgets */
        $widgets = config("modules.widgets.{$espace}", []);

        $vues = [];
        foreach ($widgets as $widget) {
            if ($this->estActif($widget['module'])) {
                $vues[] = $widget['view'];
            }
        }

        return $vues;
    }
}
