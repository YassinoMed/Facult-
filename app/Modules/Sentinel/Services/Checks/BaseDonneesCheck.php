<?php

namespace App\Modules\Sentinel\Services\Checks;

use App\Contracts\SentinelCheckInterface;
use App\Services\SupervisionService;

/**
 * INFRA_BASE (critique) : réutilise la sonde `SupervisionService`
 * (aucune logique dupliquée) — incident seulement si injoignable.
 */
class BaseDonneesCheck implements SentinelCheckInterface
{
    public function __construct(
        private readonly SupervisionService $supervision,
    ) {}

    public function code(): string
    {
        return 'INFRA_BASE';
    }

    public function categorie(): string
    {
        return 'infrastructure';
    }

    public function gravite(): string
    {
        return 'critique';
    }

    public function module(): ?string
    {
        return 'infrastructure';
    }

    public function controler(): array
    {
        foreach ($this->supervision->sondes() as $sonde) {
            if ($sonde['nom'] === 'Base de données' && ! $sonde['ok']) {
                return [[
                    'titre' => 'Base de données injoignable',
                    'diagnostic' => 'La sonde `select 1` a échoué : '.$sonde['detail'],
                    'recommandation' => 'Vérifier le serveur MySQL, les variables DB_* et l’espace disque, puis relancer `sentinel:controler`.',
                ]];
            }
        }

        return [];
    }
}
