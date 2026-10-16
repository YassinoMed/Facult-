<?php

namespace App\Modules\Sentinel\Services\Checks;

use App\Contracts\SentinelCheckInterface;
use App\Models\VerrouillagePin;
use App\Services\ParametreService;

/**
 * Platform Sentinel — contrôle du verrouillage secondaire (LOOP N).
 * Lecture seule, constats sans aucune donnée personnelle (comptes uniquement).
 */
class VerrouillageSecuriteCheck implements SentinelCheckInterface
{
    public function code(): string
    {
        return 'SECURITE_VERROUILLAGE';
    }

    public function categorie(): string
    {
        return 'securite';
    }

    public function gravite(): string
    {
        return 'avertissement';
    }

    public function module(): ?string
    {
        return 'administration';
    }

    public function controler(): array
    {
        $constats = [];

        try {
            $parametres = app(ParametreService::class);

            if (! (bool) $parametres->get('verrouillage.actif', true)) {
                $constats[] = [
                    'titre' => 'Verrouillage secondaire désactivé',
                    'diagnostic' => 'Le paramètre `verrouillage.actif` vaut 0 : aucune session ne se verrouille après inactivité, même avec un code défini.',
                    'recommandation' => 'Réactiver le verrouillage (`verrouillage.actif = 1`) sauf décision de sécurité tracée.',
                ];
            }

            $bloques = VerrouillagePin::whereNotNull('bloque_until')
                ->where('bloque_until', '>', now())
                ->count();

            if ($bloques > 0) {
                $constats[] = [
                    'titre' => 'Codes de verrouillage temporairement bloqués',
                    'diagnostic' => "{$bloques} compte(s) avec code temporairement bloqué (temporisation anti-brute-force après échecs répétés).",
                    'recommandation' => 'Vérifier qu\'il s\'agit de titulaires légitimes ; en cas d\'attaque, révoquer les sessions suspectes.',
                ];
            }
        } catch (\Throwable) {
        }

        return $constats;
    }
}
