<?php

namespace App\Modules\AiAssistant\Exceptions;

/**
 * Invocation d'outil refusée par le registre / la politique serveur :
 * outil inconnu, permission absente, paramètres invalides, chemin hors
 * sandbox. Jamais déclenchée par le contenu du modèle (Lot 3/7/12).
 */
final class OutilRefuseException extends AiAssistantException
{
    public function __construct(string $outil, string $motif)
    {
        parent::__construct("Outil IA « {$outil} » refusé : {$motif}.");
    }

    public function codePublic(): string
    {
        return 'ai.outil.refuse';
    }
}
