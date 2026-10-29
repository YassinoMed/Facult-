<?php

namespace App\Modules\AssistantIa\DTO;

/**
 * Message d'un échange conversationnel avec l'IA.
 */
final class MessageIa
{
    public readonly string $role;

    public readonly string $contenu;

    public readonly ?string $nomOutil;

    public readonly ?string $toolCallId;

    public readonly array $appelsOutils;

    public readonly array $metadata;

    /**
     * @param  array<int, AppelOutil>  $appelsOutils
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        string $role, // 'system', 'user', 'assistant', 'tool'
        string $contenu,
        ?string $nomOutil = null,
        ?string $toolCallId = null,
        array $appelsOutils = [],
        array $metadata = [],
        ?string $idAppelOutil = null
    ) {
        $this->role = $role;
        $this->contenu = $contenu;
        $this->nomOutil = $nomOutil;
        $this->toolCallId = $toolCallId ?? $idAppelOutil;
        $this->appelsOutils = $appelsOutils;
        $this->metadata = $metadata;
    }

    public function __get(string $name): mixed
    {
        if ($name === 'idAppelOutil') {
            return $this->toolCallId;
        }

        return null;
    }

    public function __isset(string $name): bool
    {
        if ($name === 'idAppelOutil') {
            return $this->toolCallId !== null;
        }

        return false;
    }

    public static function systeme(string $contenu): self
    {
        return new self('system', $contenu);
    }

    public static function utilisateur(string $contenu, array $metadata = []): self
    {
        return new self('user', $contenu, metadata: $metadata);
    }

    public static function assistant(string $contenu, array $appelsOutils = [], array $metadata = []): self
    {
        return new self('assistant', $contenu, appelsOutils: $appelsOutils, metadata: $metadata);
    }

    public static function outil(string $contenu, ?string $nomOutil = null, ?string $toolCallId = null): self
    {
        if ($toolCallId === null && $nomOutil !== null && (str_starts_with($nomOutil, 'call_') || str_contains($nomOutil, '-'))) {
            return new self('tool', $contenu, nomOutil: null, toolCallId: $nomOutil);
        }

        return new self('tool', $contenu, nomOutil: $nomOutil, toolCallId: $toolCallId);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $arr = [
            'role' => $this->role,
            'content' => $this->contenu,
        ];

        if ($this->role !== 'tool' && $this->nomOutil !== null) {
            $arr['name'] = $this->nomOutil;
        }

        if ($this->toolCallId !== null) {
            $arr['tool_call_id'] = $this->toolCallId;
        }

        if (! empty($this->appelsOutils)) {
            $arr['tool_calls'] = array_map(fn (AppelOutil $a) => $a->toArray(), $this->appelsOutils);
        }

        return $arr;
    }
}
