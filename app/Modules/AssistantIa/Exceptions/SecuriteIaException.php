<?php

namespace App\Modules\AssistantIa\Exceptions;

use Throwable;

/**
 * Déclenchée lors d'une tentative d'injection, d'exfiltration ou d'accès non autorisé.
 */
class SecuriteIaException extends AssistantIaException
{
    public function __construct(
        string $message = 'Violation de sécurité détectée par le module IA.',
        public readonly array $contexte = [],
        int $code = 403,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
