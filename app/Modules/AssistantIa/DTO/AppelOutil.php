<?php

namespace App\Modules\AssistantIa\DTO;

/**
 * Représente un appel d'outil déclenché par le modèle IA.
 */
final class AppelOutil
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public readonly string $id,
        public readonly string $nomOutil,
        public readonly array $arguments = []
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => 'function',
            'function' => [
                'name' => $this->nomOutil,
                'arguments' => json_encode($this->arguments, JSON_UNESCAPED_UNICODE),
            ],
        ];
    }
}
