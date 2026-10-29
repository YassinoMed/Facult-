<?php

namespace App\Modules\AssistantIa\DTO;

/**
 * Réponse normalisée renvoyée par le fournisseur d'IA.
 */
final class ReponseIa
{
    /**
     * @param  array<int, AppelOutil>  $appelsOutils
     * @param  array<int, array{source: string, titre: string, chemin: string, extrait?: string}>  $sources
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $texte,
        public readonly array $appelsOutils = [],
        public readonly int $tokensPrompt = 0,
        public readonly int $tokensReponse = 0,
        public readonly float $latenceMs = 0.0,
        public readonly string $fournisseur = '',
        public readonly string $modele = '',
        public readonly array $sources = [],
        public readonly array $metadata = []
    ) {}

    public static function texte(string $texte, string $modele = '', int $tokensPrompt = 0, int $tokensReponse = 0, float $latenceMs = 0.0, string $fournisseur = ''): self
    {
        return new self(
            texte: $texte,
            tokensPrompt: $tokensPrompt,
            tokensReponse: $tokensReponse,
            latenceMs: $latenceMs,
            fournisseur: $fournisseur,
            modele: $modele
        );
    }

    public function aDesAppelsOutils(): bool
    {
        return ! empty($this->appelsOutils);
    }

    public function totalTokens(): int
    {
        return $this->tokensPrompt + $this->tokensReponse;
    }

    /**
     * @return array<int, array{source_id: string, title: string, excerpt: string, path?: string}>
     */
    public function citations(): array
    {
        return $this->metadata['citations'] ?? [];
    }

    public function preuveInsuffisante(): bool
    {
        return (bool) ($this->metadata['insufficient_evidence'] ?? false);
    }

    /**
     * Schéma logique officiel UniRegistre RAG.
     *
     * @return array{answer: string, citations: array<int, mixed>, insufficient_evidence: bool, confidence?: array<string, mixed>}
     */
    public function versStructureLogique(): array
    {
        return [
            'answer' => $this->texte,
            'citations' => $this->citations(),
            'insufficient_evidence' => $this->preuveInsuffisante(),
            'confidence' => $this->metadata['confidence'] ?? null,
        ];
    }
}
