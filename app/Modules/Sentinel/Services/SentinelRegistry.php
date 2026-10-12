<?php

namespace App\Modules\Sentinel\Services;

use App\Contracts\SentinelCheckInterface;

/**
 * Platform Sentinel — registre des contrôles. Source : `config/sentinel.php`
 * (`checks` = classes), plus enregistrement à chaud (tests, extensions).
 * Les défauts d'un contrôle vivent dans sa classe ; la table
 * `sentinel_regles` ne porte que les surcharges (actif/gravité/seuils).
 */
final class SentinelRegistry
{
    /** @var array<int, SentinelCheckInterface> */
    private array $supplementaires = [];

    /**
     * @return array<int, SentinelCheckInterface>
     */
    public function controles(): array
    {
        $controles = [];

        foreach ((array) config('sentinel.checks', []) as $classe) {
            if (is_string($classe) && class_exists($classe)) {
                $controles[] = app($classe);
            }
        }

        return [...$controles, ...$this->supplementaires];
    }

    public function enregistrer(SentinelCheckInterface $controle): void
    {
        $this->supplementaires[] = $controle;
    }
}
