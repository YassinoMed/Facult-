<?php

namespace App\Modules\Sentinel\Services\Checks;

use App\Contracts\SentinelCheckInterface;
use App\Services\SupervisionService;

/**
 * INFRA_CACHE (erreur) : réutilise la sonde `SupervisionService` —
 * incident seulement si le ping cache échoue.
 */
class CacheCheck implements SentinelCheckInterface
{
    public function __construct(
        private readonly SupervisionService $supervision,
    ) {}

    public function code(): string
    {
        return 'INFRA_CACHE';
    }

    public function categorie(): string
    {
        return 'infrastructure';
    }

    public function gravite(): string
    {
        return 'erreur';
    }

    public function module(): ?string
    {
        return 'infrastructure';
    }

    public function controler(): array
    {
        foreach ($this->supervision->sondes() as $sonde) {
            if ($sonde['nom'] === 'Cache' && ! $sonde['ok']) {
                return [[
                    'titre' => 'Cache inopérant',
                    'diagnostic' => 'Le ping cache a échoué (pilote : '.$sonde['detail'].'). Verrous, idempotence et préchauffage sont dégradés.',
                    'recommandation' => 'Vérifier CACHE_STORE et la table `cache`, puis vider le cache applicatif.',
                ]];
            }
        }

        return [];
    }
}
