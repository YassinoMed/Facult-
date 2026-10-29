<?php

namespace App\Services\Kpi\DTO;

/**
 * Définition formalisée et versionnée d'un indicateur clé de performance (KPI).
 * Chaque indicateur porte obligatoirement son nom, sa catégorie, sa définition,
 * sa formule, sa source de vérité, ses seuils d'alerte et son SLA de fraîcheur.
 */
final class DefinitionKpi
{
    /**
     * @param  array{
     *     critique_si_inf?: float|int,
     *     attention_si_inf?: float|int,
     *     critique_si_sup?: float|int,
     *     attention_si_sup?: float|int
     * }|null  $seuilsAlerte
     */
    public function __construct(
        public readonly string $code,
        public readonly string $nom,
        public readonly string $categorie,
        public readonly string $definition,
        public readonly string $formule,
        public readonly string $sourceVerite,
        public readonly string $unite,
        public readonly ?array $seuilsAlerte = null,
        public readonly int $slaFraicheurMinutes = 60,
        public readonly bool $sensiblePii = false,
    ) {}

    /**
     * Évalue le niveau d'alerte pour une valeur numérique donnée.
     * Retourne 'normal', 'attention', 'critique', ou 'indetermine'.
     */
    public function evaluerAlerte(mixed $valeur): string
    {
        if ($this->seuilsAlerte === null || ! is_numeric($valeur)) {
            return 'indetermine';
        }

        $num = (float) $valeur;

        if (isset($this->seuilsAlerte['critique_si_inf']) && $num < $this->seuilsAlerte['critique_si_inf']) {
            return 'critique';
        }
        if (isset($this->seuilsAlerte['critique_si_sup']) && $num > $this->seuilsAlerte['critique_si_sup']) {
            return 'critique';
        }
        if (isset($this->seuilsAlerte['attention_si_inf']) && $num < $this->seuilsAlerte['attention_si_inf']) {
            return 'attention';
        }
        if (isset($this->seuilsAlerte['attention_si_sup']) && $num > $this->seuilsAlerte['attention_si_sup']) {
            return 'attention';
        }

        return 'normal';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'nom' => $this->nom,
            'categorie' => $this->categorie,
            'definition' => $this->definition,
            'formule' => $this->formule,
            'source_verite' => $this->sourceVerite,
            'unite' => $this->unite,
            'seuils_alerte' => $this->seuilsAlerte,
            'sla_fraicheur_minutes' => $this->slaFraicheurMinutes,
            'sensible_pii' => $this->sensiblePii,
        ];
    }
}
