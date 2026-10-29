<?php

namespace App\Modules\AiAssistant\Data;

/**
 * Contexte serveur d'exécution d'un outil (Lot 1) : identité, rôle et
 * périmètre facultaire reconstruits côté serveur — jamais fournis par
 * le modèle ni par le navigateur.
 */
final readonly class ContexteExecution
{
    public function __construct(
        public int $utilisateurId,
        public string $role,
        public string $mode,
        public ?int $faculteId = null,
        public ?int $conversationId = null,
    ) {
        if (! in_array($this->mode, ['developpeur', 'metier'], true)) {
            throw new \InvalidArgumentException("Mode IA inconnu : {$this->mode}.");
        }
    }
}
