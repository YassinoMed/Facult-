<?php

namespace App\Modules\SimulationTemporelle\Support;

use DateTimeImmutable;

/**
 * Événement SIMULÉ (ST-01) : temps simulé + séquence garantissant un
 * ORDRE TOTAL DÉTERMINISTE (deux événements simultanés sont départagés
 * par leur numéro de planification, jamais par l'ordre d'insertion).
 */
final class EvenementSimule
{
    public function __construct(
        public readonly DateTimeImmutable $temps,
        public readonly int $sequence,
        public readonly string $type,
        public readonly array $charge = [],
    ) {}

    /** Clé de tri totale : (timestamp, séquence). */
    public function cle(): array
    {
        return [$this->temps->getTimestamp(), $this->sequence];
    }
}
