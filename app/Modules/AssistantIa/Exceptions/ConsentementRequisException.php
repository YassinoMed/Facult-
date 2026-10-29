<?php

namespace App\Modules\AssistantIa\Exceptions;

/**
 * Exception levée lorsqu'un transfert externe requiert le consentement explicite préalable de l'utilisateur.
 */
class ConsentementRequisException extends AssistantIaException
{
    /**
     * @param  array<string, mixed>  $defiConsentement
     */
    public function __construct(
        string $message,
        protected array $defiConsentement = [],
        int $code = 428,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function obtenirDefiConsentement(): array
    {
        return $this->defiConsentement;
    }
}
