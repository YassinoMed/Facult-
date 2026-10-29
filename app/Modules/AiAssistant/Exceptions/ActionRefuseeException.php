<?php

namespace App\Modules\AiAssistant\Exceptions;

/**
 * Action sensible refusée par la politique (Lot 9) : exécuteur absent
 * de la liste blanche (ex. appliquer_patch), approbation absente,
 * expirée, rejouée ou dépendance métier manquante.
 */
final class ActionRefuseeException extends AiAssistantException
{
    public function __construct(string $action, string $motif)
    {
        parent::__construct("Action IA « {$action} » refusée : {$motif}.");
    }

    public function codePublic(): string
    {
        return 'ai.action.refusee';
    }
}
