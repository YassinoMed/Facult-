<?php

namespace App\Services\Kpi\DTO;

/**
 * Snapshot immuable d'un ensemble d'indicateurs de pilotage
 * pour un périmètre donné (année, faculté, programme).
 */
final class SnapshotKpi
{
    /**
     * @param  array<string, IndicateurKpi>  $indicateurs
     * @param  array{critique: int, attention: int, normal: int, indetermine: int}  $syntheseAlertes
     */
    public function __construct(
        public readonly string $version,
        public readonly string $horodatage,
        public readonly ?int $anneeId,
        public readonly ?int $faculteId,
        public readonly ?int $programmeId,
        public readonly array $indicateurs,
        public readonly array $syntheseAlertes,
        public readonly string $statutFraicheurGlobal,
    ) {}

    public function get(string $code): ?IndicateurKpi
    {
        return $this->indicateurs[$code] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'horodatage' => $this->horodatage,
            'perimetre' => [
                'annee_id' => $this->anneeId,
                'faculte_id' => $this->faculteId,
                'programme_id' => $this->programmeId,
            ],
            'statut_fraicheur_global' => $this->statutFraicheurGlobal,
            'synthese_alertes' => $this->syntheseAlertes,
            'indicateurs' => array_map(fn (IndicateurKpi $i) => $i->toArray(), $this->indicateurs),
        ];
    }

    /**
     * Format adapté pour l'exposition dans le Data Mesh (domaine Pilotage).
     *
     * @return array<string, mixed>
     */
    public function versDataMesh(): array
    {
        $lignes = [];
        foreach ($this->indicateurs as $code => $ind) {
            $lignes[$code] = [
                'valeur' => $ind->valeur,
                'valeur_formatee' => $ind->valeurFormatee,
                'definition' => $ind->definition->definition,
                'source' => $ind->definition->sourceVerite,
                'statut_alerte' => $ind->statutAlerte,
                'statut_fraicheur' => $ind->statutFraicheur,
            ];
        }

        return [
            'version_agregats' => $this->version,
            'horodatage' => $this->horodatage,
            'perimetre' => [
                'annee_id' => $this->anneeId,
                'faculte_id' => $this->faculteId,
                'programme_id' => $this->programmeId,
            ],
            'indicateurs' => $lignes,
        ];
    }
}
