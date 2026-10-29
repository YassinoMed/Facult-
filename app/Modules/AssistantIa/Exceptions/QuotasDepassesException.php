<?php

namespace App\Modules\AssistantIa\Exceptions;

class QuotasDepassesException extends AssistantIaException
{
    public function __construct(string $message = 'Le quota d\'utilisation de l\'assistant IA est dépassé.', int $code = 429, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
