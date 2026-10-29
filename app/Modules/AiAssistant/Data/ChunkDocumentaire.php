<?php

namespace App\Modules\AiAssistant\Data;

/**
 * Extrait documentaire citable renvoyé par la recherche (Lot 6).
 * Chaque chunk est vérifiable : chemin de fichier + ordre du bloc.
 */
final readonly class ChunkDocumentaire
{
    public function __construct(
        public int $id,
        public string $titre,
        public string $chemin,
        public string $extrait,
        public int $ordre,
        public int $score = 0,
    ) {}

    /**
     * @return array{titre: string, chemin: string, extrait: string}
     */
    public function pourContexte(): array
    {
        return [
            'titre' => $this->titre,
            'chemin' => $this->chemin,
            'extrait' => $this->extrait,
        ];
    }

    /**
     * @return array{titre: string, chemin: string}
     */
    public function pourCitation(): array
    {
        return ['titre' => $this->titre, 'chemin' => $this->chemin];
    }
}
