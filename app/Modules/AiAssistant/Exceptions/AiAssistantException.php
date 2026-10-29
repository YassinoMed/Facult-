<?php

namespace App\Modules\AiAssistant\Exceptions;

/**
 * Base des erreurs typées du module AI-ASSISTANT (Lot 1).
 * Permet au contrôleur de renderer des erreurs fail-soft sans exposer
 * de détails internes ni de composer une réponse hors politique.
 */
class AiAssistantException extends \RuntimeException
{
    /** Code stable utilisable côté UI/API (aucune information sensible). */
    public function codePublic(): string
    {
        return 'ai.erreur';
    }
}
