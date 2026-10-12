<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

use function Laravel\Prompts\info;

/**
 * Révoque un token d'intégration (§46) — effet immédiat.
 */
class IntegrationTokenRevoke extends Command
{
    protected $signature = 'integration:token-revoke {id* : ID(s) du/des token(s) à révoquer}';

    protected $description = 'Révoque immédiatement un ou plusieurs tokens d\'API';

    public function handle(): int
    {
        $ids = (array) $this->argument('id');

        $revokes = PersonalAccessToken::whereIn('id', $ids)->get();

        if ($revokes->isEmpty()) {
            $this->error('Aucun token trouvé pour : '.implode(', ', $ids));

            return self::FAILURE;
        }

        foreach ($revokes as $token) {
            $token->delete();
            info("Token #{$token->id} ({$token->name}) révoqué.");
        }

        return self::SUCCESS;
    }
}
