<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Liste les tokens d'intégration actifs (§46) — jamais les valeurs.
 */
class IntegrationTokens extends Command
{
    protected $signature = 'integration:tokens';

    protected $description = 'Liste les tokens d\'API actifs (id, nom, scopes, dernière utilisation)';

    public function handle(): int
    {
        $tokens = PersonalAccessToken::orderByDesc('created_at')->get();

        if ($tokens->isEmpty()) {
            $this->info('Aucun token actif. Créez-en un avec integration:token-create.');

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Nom', 'Scopes', 'Dernière utilisation', 'Créé le'],
            $tokens->map(fn (PersonalAccessToken $t) => [
                $t->id,
                $t->name,
                implode(', ', $t->abilities ?? []),
                $t->last_used_at?->format('d/m/Y H:i') ?? '—',
                $t->created_at->format('d/m/Y H:i'),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
