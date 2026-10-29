<?php

namespace App\Modules\SimulationTemporelle\Support;

use DateTimeImmutable;

/**
 * Horloge SIMULÉE (ST-01) : temps virtuel explicite, avancé par pas
 * fixes. AUCUNE dépendance à l'horloge système (jamais now()) — le
 * temps est injecté et reproductible (mandat simulateur temporel).
 */
final class HorlogeSimulee
{
    private int $nbPas = 0;

    public function __construct(
        private readonly DateTimeImmutable $origine,
        private readonly \DateInterval $pas,
    ) {}

    public static function annuelle(DateTimeImmutable $origine): self
    {
        return new self($origine, new \DateInterval('P1Y'));
    }

    public function avancer(): self
    {
        $clone = new self($this->origine, $this->pas);
        $clone->nbPas = $this->nbPas + 1;

        return $clone;
    }

    /** Instant courant = origine + nbPas × pas (recalcul pur). */
    public function instant(): DateTimeImmutable
    {
        $instant = $this->origine;

        for ($i = 0; $i < $this->nbPas; $i++) {
            $instant = $instant->add($this->pas);
        }

        return $instant;
    }

    public function nbPas(): int
    {
        return $this->nbPas;
    }
}
