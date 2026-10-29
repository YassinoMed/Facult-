<?php

namespace App\Modules\AiAssistant\Exceptions;

/**
 * Fournisseur IA indisponible : timeout, 5xx, réponse invalide.
 * Fail-soft prévu : message générique à l'utilisateur, journal non
 * sensible (fournisseur + code d'état uniquement — Lot 11).
 */
final class FournisseurIndisponibleException extends AiAssistantException
{
    public function __construct(
        string $fournisseur,
        string $motif = 'indisponible',
    ) {
        parent::__construct("Fournisseur IA « {$fournisseur} » indisponible ({$motif}).");
    }

    public function codePublic(): string
    {
        return 'ai.fournisseur.indisponible';
    }
}
