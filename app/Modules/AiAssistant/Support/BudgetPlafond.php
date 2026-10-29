<?php

namespace App\Modules\AiAssistant\Support;

use App\Models\User;
use App\Modules\AiAssistant\Exceptions\PlafondAtteintException;
use Illuminate\Support\Facades\Cache;

/**
 * Plafonds de consommation (Lot 2/11) — côté SERVEUR, invaloiables
 * depuis le navigateur : compteur horaire par utilisateur et par mode.
 */
final class BudgetPlafond
{
    public static function verifierEtIncrementer(User $utilisateur, string $mode): void
    {
        $limites = (array) config('ai-assistant.limites', []);
        $maxParHeure = $mode === 'developpeur'
            ? (int) ($limites['appels_par_heure_developpeur'] ?? 8)
            : (int) ($limites['appels_par_heure'] ?? 20);

        $cle = 'ai-budget:'.$utilisateur->id.':'.$mode.':'.now()->format('YmdH');

        if (Cache::has($cle)) {
            $appels = (int) Cache::increment($cle);
        } else {
            Cache::put($cle, 1, 3600);
            $appels = 1;
        }

        if ($appels > $maxParHeure) {
            throw new PlafondAtteintException('appels_par_heure');
        }
    }
}
