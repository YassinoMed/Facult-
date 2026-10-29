<?php

namespace App\Modules\AssistantIa\DTO;

/**
 * Résultat normalisé de l'exécution d'un outil par l'assistant.
 */
final class ResultatOutil
{
    /**
     * @param  array<string, mixed>  $donnees
     * @param  array<int, array{titre: string, url_ou_chemin: string, extrait?: string}>  $sources
     * @param  array<string, mixed>  $payloadAction
     */
    public function __construct(
        public readonly bool $succes,
        public readonly array $donnees,
        public readonly string $message,
        public readonly array $sources = [],
        public readonly bool $necessiteApprobation = false,
        public readonly ?string $typeAction = null,
        public readonly array $payloadAction = []
    ) {}

    /**
     * Succès standard en lecture seule.
     *
     * @param  array<string, mixed>  $donnees
     * @param  array<int, array{titre: string, url_ou_chemin: string, extrait?: string}>  $sources
     */
    public static function succes(array $donnees, string $message = 'Succès', array $sources = []): self
    {
        return new self(
            succes: true,
            donnees: $donnees,
            message: $message,
            sources: $sources
        );
    }

    /**
     * Échec ou refus sécurisé.
     */
    public static function echec(string $message, array $donnees = []): self
    {
        return new self(
            succes: false,
            donnees: $donnees,
            message: $message
        );
    }

    /**
     * Action sensible nécessitant validation humaine préalable (2-person rule / 4-eyes).
     *
     * @param  array<string, mixed>  $payloadAction
     */
    public static function requiertApprobation(string $typeAction, array $payloadAction, string $resumeImpact): self
    {
        return new self(
            succes: true,
            donnees: ['statut' => 'en_attente_approbation', 'impact' => $resumeImpact],
            message: "Action sensible proposée : {$resumeImpact}. Approbation humaine requise.",
            necessiteApprobation: true,
            typeAction: $typeAction,
            payloadAction: $payloadAction
        );
    }

    /**
     * Sérialisation JSON pour le contexte du modèle.
     */
    public function versChaineJson(): string
    {
        return json_encode([
            'succes' => $this->succes,
            'message' => $this->message,
            'donnees' => $this->donnees,
            'sources' => $this->sources,
            'necessite_approbation' => $this->necessiteApprobation,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
