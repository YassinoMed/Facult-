<?php

namespace App\Modules\Schedule\Services;

use App\Services\ParametreService;

/**
 * PHASE A (Smart Timetables) — accès typé et borné aux paramètres du futur
 * moteur de génération (Phase B). Défauts sûrs : valeurs conservatrices si
 * la ligne de paramètre est absente ; bornes de sécurité appliquées à la
 * lecture (un paramètre aberrant ne peut pas casser le moteur).
 *
 * Aucune publication automatique n'existe par conception : la génération
 * produira toujours un BROUILLON soumis à validation humaine.
 */
class ScheduleGenerationSettings
{
    public function __construct(
        private readonly ParametreService $parametres,
    ) {}

    /** Pondération de la minimisation des trous (0-10). */
    public function poidsTrous(): int
    {
        return $this->entier('planning.generation.poids_trous', 5, 0, 10);
    }

    /** Pondération de l'équilibre de charge entre les jours (0-10). */
    public function poidsEquilibreJours(): int
    {
        return $this->entier('planning.generation.poids_equilibre_jours', 5, 0, 10);
    }

    /** Pondération de la continuité des séances d'une même matière (0-10). */
    public function poidsContinuite(): int
    {
        return $this->entier('planning.generation.poids_continuite', 3, 0, 10);
    }

    /** Durée maximale d'une séquence continue (minutes). */
    public function dureeMaxSequenceMinutes(): int
    {
        return $this->entier('planning.generation.duree_max_sequence_minutes', 240, 30, 720);
    }

    /** Garde-fou anti-boucle du moteur (itérations maximales). */
    public function iterationMax(): int
    {
        return $this->entier('planning.generation.iteration_max', 50000, 1000, 1000000);
    }

    private function entier(string $cle, int $defaut, int $min, int $max): int
    {
        $valeur = $this->parametres->get($cle, $defaut);

        if (! is_numeric($valeur)) {
            return $defaut;
        }

        return max($min, min($max, (int) $valeur));
    }
}
