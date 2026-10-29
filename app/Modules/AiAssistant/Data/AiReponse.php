<?php

namespace App\Modules\AiAssistant\Data;

/**
 * Réponse structurée d'un fournisseur IA (Lot 1).
 *
 * `texte` est du texte brut destiné à l'affichage échappé (x-text en
 * Vue/Alpine, jamais {!! !!}). Les métadonnées sont non sensibles :
 * fournisseur, modèle, latence, jetons, outils réellement invoqués.
 */
final readonly class AiReponse
{
    /**
     * @param  array<int, array{titre: string, chemin: string}>  $sources
     * @param  array<string, mixed>  $metadonnees
     */
    public function __construct(
        public string $texte,
        public string $fournisseur,
        public string $modele,
        public int $latenceMs = 0,
        public ?int $jetonsEntree = null,
        public ?int $jetonsSortie = null,
        public array $sources = [],
        public array $metadonnees = [],
    ) {}
}
