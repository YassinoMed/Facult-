<?php

namespace App\Modules\Evaluation\Services;

/**
 * Résultat structuré d'une prévisualisation de transition (C3/C4).
 *
 * Objet de valeur immuable : à données identiques, la prévisualisation
 * est identique. La décision définitive reste recalculée côté serveur
 * au moment de l'application (ExamenService::transiter).
 */
final class ResultatTransition
{
    /**
     * @param  array<int, string>  $conditionsSatisfaites
     * @param  array<int, string>  $bloquages
     */
    public function __construct(
        public readonly int $examenId,
        public readonly string $statutCourant,
        public readonly string $cible,
        public readonly bool $acceptable,
        public readonly array $conditionsSatisfaites,
        public readonly array $bloquages,
        public readonly ?string $message,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'examen_id' => $this->examenId,
            'statut_courant' => $this->statutCourant,
            'cible' => $this->cible,
            'acceptable' => $this->acceptable,
            'conditions_satisfaites' => $this->conditionsSatisfaites,
            'bloquages' => $this->bloquages,
            'message' => $this->message,
        ];
    }
}
