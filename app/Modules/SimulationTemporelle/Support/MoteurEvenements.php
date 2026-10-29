<?php

namespace App\Modules\SimulationTemporelle\Support;

/**
 * Moteur d'événements SIMULÉS (ST-01) : file triée par (temps, séquence)
 * — ordre total déterministe ; le handler peut replanifier pendant le
 * traitement (boucle jusqu'à épuisement ou plafond anti-coût). Le temps
 * provient UNIQUEMENT des événements : aucune horloge système.
 */
final class MoteurEvenements
{
    public const PLAFOND_EVENEMENTS = 10000;

    /** @var array<int, EvenementSimule> */
    private array $file = [];

    private int $prochaineSequence = 0;

    private int $traites = 0;

    public function planifier(\DateTimeImmutable $temps, string $type, array $charge = []): EvenementSimule
    {
        if (count($this->file) >= self::PLAFOND_EVENEMENTS) {
            throw new \DomainException('Plafond de '.self::PLAFOND_EVENEMENTS.' événements planifiés atteint.');
        }

        $evenement = new EvenementSimule($temps, $this->prochaineSequence++, $type, $charge);
        $this->file[] = $evenement;

        return $evenement;
    }

    /**
     * Traite la file dans l'ordre total (temps, séquence). Le handler
     * reçoit l'événement et peut planifier de nouveaux événements.
     *
     * @param  callable(EvenementSimule): void  $handler
     * @return int nombre d'événements traités
     */
    public function traiter(callable $handler): int
    {
        while ($this->file !== []) {
            if ($this->traites >= self::PLAFOND_EVENEMENTS) {
                throw new \DomainException('Plafond de '.self::PLAFOND_EVENEMENTS.' événements traités atteint (boucle ?).');
            }

            $evenement = $this->extraireMinimum();
            $this->traites++;
            $handler($evenement);
        }

        return $this->traites;
    }

    private function extraireMinimum(): EvenementSimule
    {
        $indiceMin = 0;

        foreach ($this->file as $i => $evenement) {
            if ($this->file[$indiceMin] === null
                || $this->compareCles($evenement->cle(), $this->file[$indiceMin]->cle()) < 0) {
                $indiceMin = $i;
            }
        }

        $minimum = $this->file[$indiceMin];
        unset($this->file[$indiceMin]);
        $this->file = array_values($this->file);

        return $minimum;
    }

    /** @param array{0: int, 1: int} $a @param array{0: int, 1: int} $b */
    private function compareCles(array $a, array $b): int
    {
        return $a[0] <=> $b[0] ?: $a[1] <=> $b[1];
    }
}
