<?php

namespace App\Modules\AiAssistant\Exceptions;

/**
 * Plafond atteint : budget d'appels par heure, jetons, iterations
 * d'agent. Message public sans chiffre sensible ; compteur côté serveur.
 */
final class PlafondAtteintException extends AiAssistantException
{
    public function __construct(string $plafond)
    {
        parent::__construct("Plafond d'utilisation IA atteint ({$plafond}). Réessayez plus tard.");
    }

    public function codePublic(): string
    {
        return 'ai.plafond.atteint';
    }
}
