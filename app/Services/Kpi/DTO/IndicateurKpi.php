<?php

namespace App\Services\Kpi\DTO;

/**
 * Valeur calculée et contextualisée d'un KPI à un instant T.
 * Inclut le statut d'alerte, la fraîcheur des données sous-jacentes
 * et des métadonnées d'explication garantissant la transparence.
 */
final class IndicateurKpi
{
    public function __construct(
        public readonly DefinitionKpi $definition,
        public readonly mixed $valeur,
        public readonly string $valeurFormatee,
        public readonly string $statutAlerte, // 'normal', 'attention', 'critique', 'indetermine'
        public readonly string $statutFraicheur, // 'a_jour', 'perime', 'donnees_insuffisantes', 'indisponible'
        public readonly ?string $derniereMaj = null,
        public readonly ?string $explication = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->definition->code,
            'nom' => $this->definition->nom,
            'categorie' => $this->definition->categorie,
            'valeur' => $this->valeur,
            'valeur_formatee' => $this->valeurFormatee,
            'unite' => $this->definition->unite,
            'statut_alerte' => $this->statutAlerte,
            'statut_fraicheur' => $this->statutFraicheur,
            'derniere_maj' => $this->derniereMaj,
            'definition' => $this->definition->definition,
            'formule' => $this->definition->formule,
            'source' => $this->definition->sourceVerite,
            'explication' => $this->explication,
        ];
    }
}
