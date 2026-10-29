<?php

namespace App\Modules\AssistantIa\DTO;

/**
 * Demande d'inférence structurée transmise au fournisseur d'IA.
 */
final class DemandeIa
{
    /**
     * @param  array<int, MessageIa>  $messages
     * @param  array<int, array<string, mixed>>  $outilsDisponibles
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly array $messages,
        public readonly string $promptSysteme = '',
        public readonly array $outilsDisponibles = [],
        public readonly float $temperature = 0.2,
        public readonly int $maxTokens = 2048,
        public readonly array $metadata = []
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function messagesEnTableau(): array
    {
        $res = [];
        if (! empty($this->promptSysteme)) {
            $res[] = [
                'role' => 'system',
                'content' => $this->promptSysteme,
            ];
        }

        foreach ($this->messages as $m) {
            $res[] = $m->toArray();
        }

        return $res;
    }

    /**
     * Alias explicite pour les passerelles de modèles.
     *
     * @return array<int, array<string, mixed>>
     */
    public function preparerMessagesPourFournisseur(): array
    {
        return $this->messagesEnTableau();
    }

    /**
     * Retrouve le dernier message utilisateur dans l'historique.
     */
    public function dernierMessageUtilisateur(): ?MessageIa
    {
        for ($i = count($this->messages) - 1; $i >= 0; $i--) {
            if ($this->messages[$i]->role === 'user') {
                return $this->messages[$i];
            }
        }

        return null;
    }
}
