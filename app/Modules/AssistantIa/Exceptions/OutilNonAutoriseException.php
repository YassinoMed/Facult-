<?php

namespace App\Modules\AssistantIa\Exceptions;

use Throwable;

class OutilNonAutoriseException extends SecuriteIaException
{
    public function __construct(string $nomOutil, string $raison = 'Accès interdit', array $contexte = [], int $code = 403, ?Throwable $previous = null)
    {
        parent::__construct("L'outil '{$nomOutil}' n'est pas autorisé : {$raison}", $contexte, $code, $previous);
    }
}
