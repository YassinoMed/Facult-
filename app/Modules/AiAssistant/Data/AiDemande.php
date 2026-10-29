<?php

namespace App\Modules\AiAssistant\Data;

/**
 * Demande structurée envoyée à un fournisseur IA (Lot 1).
 *
 * AUCUNE donnée personnelle ne doit transiter ici : `question` est la
 * formulation de l'utilisateur, `contexte` des extraits documentaires
 * déjà filtrés par permissions, `historique` les derniers messages de
 * la conversation bornés par config('ai-assistant.contexte').
 */
final readonly class AiDemande
{
    /**
     * @param  array<int, array{titre: string, chemin: string, extrait: string}>  $contexte
     * @param  array<int, array{role: string, contenu: string}>  $historique
     * @param  array<int, string>  $outilsAutorises
     */
    public function __construct(
        public string $question,
        public string $mode,
        public int $utilisateurId,
        public ?int $faculteId,
        public array $contexte = [],
        public array $historique = [],
        public array $outilsAutorises = [],
        public ?int $jetonsMax = null,
    ) {
        if (! in_array($this->mode, ['developpeur', 'metier'], true)) {
            throw new \InvalidArgumentException("Mode IA inconnu : {$this->mode}.");
        }
    }
}
