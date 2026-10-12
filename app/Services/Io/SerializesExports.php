<?php

namespace App\Services\Io;

/**
 * Serialiseurs d'export — un service par format (§36).
 */
interface SerializesExports
{
    public function format(): string;

    /** @param array<int, array<string, mixed>> $lignes */
    public function serialize(array $entetes, array $lignes): string;

    /**
     * MIME type de la réponse HTTP.
     */
    public function mimeType(): string;
}
