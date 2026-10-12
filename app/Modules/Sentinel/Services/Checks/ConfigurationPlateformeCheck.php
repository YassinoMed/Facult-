<?php

namespace App\Modules\Sentinel\Services\Checks;

use App\Contracts\SentinelCheckInterface;
use App\Models\AnneeUniversitaire;
use App\Models\Faculte;

/**
 * Platform Sentinel — contrôle de la configuration centrale de l'application.
 * Vérifie la présence d'une année universitaire active et de structures pédagogiques minimales.
 */
class ConfigurationPlateformeCheck implements SentinelCheckInterface
{
    public function code(): string
    {
        return 'CONFIG_COHERENCE';
    }

    public function categorie(): string
    {
        return 'configuration';
    }

    public function gravite(): string
    {
        return 'erreur';
    }

    public function module(): ?string
    {
        return 'referentiel';
    }

    public function controler(): array
    {
        $constats = [];

        // 1. Année universitaire active
        try {
            $anneeActive = AnneeUniversitaire::where('active', true)->exists();
            if (! $anneeActive) {
                $constats[] = [
                    'titre' => 'Aucune année universitaire active',
                    'diagnostic' => "La plateforme n'a aucune année universitaire marquée comme active dans `annees_universitaires`.",
                    'recommandation' => 'Activer l’année universitaire courante dans l’espace d’administration (Paramètres > Années universitaires).',
                ];
            }
        } catch (\Throwable) {
            // Ignoré si la table n'est pas encore migrée
        }

        // 2. Facultés / Établissements actifs
        try {
            $facultesActives = Faculte::where('active', true)->count();
            if ($facultesActives === 0) {
                $constats[] = [
                    'titre' => 'Aucune faculté active configurée',
                    'diagnostic' => 'Aucune faculté ou établissement actif n’a été détecté dans le référentiel.',
                    'recommandation' => 'Créer ou activer au moins une faculté pour permettre les inscriptions et affectations.',
                ];
            }
        } catch (\Throwable) {
        }

        return $constats;
    }
}
