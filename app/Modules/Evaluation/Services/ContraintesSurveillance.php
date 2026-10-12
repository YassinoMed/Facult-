<?php

namespace App\Modules\Evaluation\Services;

/**
 * Contraintes effectives d'une exécution du moteur (immuable).
 * Valeurs par défaut = constantes historiques de
 * `SurveillanceEquilibrageService` (non-régression garantie).
 */
class ContraintesSurveillance
{
    public function __construct(
        public readonly int $minSurveillants = 2,
        public readonly int $minParSalle = 1,
        public readonly int $ratioEtudiants = 50,
        public readonly ?int $seuilGrandeSalle = null,
        public readonly ?int $minSurveillantsGrandeSalle = null,
        public readonly int $nbGardes = 1,
        public readonly ?int $maxSurveillants = null,
        public readonly float $poidsSurveillanceActive = 1.0,
        public readonly float $poidsGardeReserve = 0.5,
        public readonly int $maxJours = 3,
        public readonly string $modeLimite = 'souple',
        public readonly bool $actifChargeTotale = true,
        public readonly float $poidsChargeTotale = 10.0,
        public readonly bool $actifChargeJour = true,
        public readonly float $poidsChargeJour = 15.0,
        public readonly bool $actifGardes = true,
        public readonly float $poidsGardes = 8.0,
        public readonly bool $actifLimiteJours = true,
        public readonly float $penaliteDepassementJours = 35.0,
        public readonly bool $actifDispersion = true,
        public readonly float $penaliteDispersion = 10.0,
        public readonly bool $actifNouveauJour = true,
        public readonly float $penaliteNouveauJour = 2.0,
        public readonly bool $actifChargeEnseignement = false,
        public readonly float $poidsChargeEnseignement = 5.0,
        public readonly string $modeCalculChargeEnseignement = 'heures_brutes',
        public readonly ?float $seuilSurchargeEnseignement = null,
        public readonly ?int $maxSurveillancesEnseignant = null,
        public readonly ?int $configId = null,
        public readonly int $configVersion = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     */
    public static function depuisParams(array $params): self
    {
        return new self(
            minSurveillants: (int) ($params['min_surveillants'] ?? 2),
            minParSalle: (int) ($params['min_par_salle'] ?? 1),
            ratioEtudiants: (int) ($params['ratio_etudiants'] ?? 50),
            seuilGrandeSalle: isset($params['seuil_grande_salle']) && $params['seuil_grande_salle'] !== null && $params['seuil_grande_salle'] !== ''
                ? (int) $params['seuil_grande_salle'] : null,
            minSurveillantsGrandeSalle: isset($params['min_surveillants_grande_salle']) && $params['min_surveillants_grande_salle'] !== null && $params['min_surveillants_grande_salle'] !== ''
                ? (int) $params['min_surveillants_grande_salle'] : null,
            nbGardes: (int) ($params['nb_gardes'] ?? 1),
            maxSurveillants: isset($params['max_surveillants']) && $params['max_surveillants'] !== null
                ? (int) $params['max_surveillants'] : null,
            poidsSurveillanceActive: (float) ($params['poids_surveillance_active'] ?? 1.0),
            poidsGardeReserve: (float) ($params['poids_garde_reserve'] ?? 0.5),
            maxJours: (int) ($params['max_jours'] ?? 3),
            modeLimite: (string) ($params['mode_limite'] ?? 'souple'),
            actifChargeTotale: isset($params['actif_charge_totale']) ? (bool) $params['actif_charge_totale'] : true,
            poidsChargeTotale: (float) ($params['poids_charge_totale'] ?? 10.0),
            actifChargeJour: isset($params['actif_charge_jour']) ? (bool) $params['actif_charge_jour'] : true,
            poidsChargeJour: (float) ($params['poids_charge_jour'] ?? 15.0),
            actifGardes: isset($params['actif_gardes']) ? (bool) $params['actif_gardes'] : true,
            poidsGardes: (float) ($params['poids_gardes'] ?? 8.0),
            actifLimiteJours: isset($params['actif_limite_jours']) ? (bool) $params['actif_limite_jours'] : true,
            penaliteDepassementJours: (float) ($params['penalite_depassement_jours'] ?? 35.0),
            actifDispersion: isset($params['actif_dispersion']) ? (bool) $params['actif_dispersion'] : true,
            penaliteDispersion: (float) ($params['penalite_dispersion'] ?? 10.0),
            actifNouveauJour: isset($params['actif_nouveau_jour']) ? (bool) $params['actif_nouveau_jour'] : true,
            penaliteNouveauJour: (float) ($params['penalite_nouveau_jour'] ?? 2.0),
            actifChargeEnseignement: isset($params['actif_charge_enseignement']) ? (bool) $params['actif_charge_enseignement'] : false,
            poidsChargeEnseignement: (float) ($params['poids_charge_enseignement'] ?? 5.0),
            modeCalculChargeEnseignement: (string) ($params['mode_calcul_charge_enseignement'] ?? 'heures_brutes'),
            seuilSurchargeEnseignement: isset($params['seuil_surcharge_enseignement']) && $params['seuil_surcharge_enseignement'] !== null
                ? (float) $params['seuil_surcharge_enseignement'] : null,
            maxSurveillancesEnseignant: isset($params['max_surveillances_enseignant']) && $params['max_surveillances_enseignant'] !== null
                ? (int) $params['max_surveillances_enseignant'] : null,
            configId: isset($params['config_id']) ? (int) $params['config_id'] : null,
            configVersion: (int) ($params['config_version'] ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function versParams(): array
    {
        return [
            'min_surveillants' => $this->minSurveillants,
            'min_par_salle' => $this->minParSalle,
            'ratio_etudiants' => $this->ratioEtudiants,
            'seuil_grande_salle' => $this->seuilGrandeSalle,
            'min_surveillants_grande_salle' => $this->minSurveillantsGrandeSalle,
            'nb_gardes' => $this->nbGardes,
            'max_surveillants' => $this->maxSurveillants,
            'poids_surveillance_active' => $this->poidsSurveillanceActive,
            'poids_garde_reserve' => $this->poidsGardeReserve,
            'max_jours' => $this->maxJours,
            'mode_limite' => $this->modeLimite,
            'actif_charge_totale' => $this->actifChargeTotale,
            'poids_charge_totale' => $this->poidsChargeTotale,
            'actif_charge_jour' => $this->actifChargeJour,
            'poids_charge_jour' => $this->poidsChargeJour,
            'actif_gardes' => $this->actifGardes,
            'poids_gardes' => $this->poidsGardes,
            'actif_limite_jours' => $this->actifLimiteJours,
            'penalite_depassement_jours' => $this->penaliteDepassementJours,
            'actif_dispersion' => $this->actifDispersion,
            'penalite_dispersion' => $this->penaliteDispersion,
            'actif_nouveau_jour' => $this->actifNouveauJour,
            'penalite_nouveau_jour' => $this->penaliteNouveauJour,
            'actif_charge_enseignement' => $this->actifChargeEnseignement,
            'poids_charge_enseignement' => $this->poidsChargeEnseignement,
            'mode_calcul_charge_enseignement' => $this->modeCalculChargeEnseignement,
            'seuil_surcharge_enseignement' => $this->seuilSurchargeEnseignement,
            'max_surveillances_enseignant' => $this->maxSurveillancesEnseignant,
            'config_id' => $this->configId,
            'config_version' => $this->configVersion,
        ];
    }

    /**
     * Besoin de surveillants actifs : formule historique, bornée par la
     * configuration (min plancher, max plafonnant optionnel).
     */
    public function besoinActifs(int $nbCandidats, int $nombreSalles): int
    {
        $besoin = max(
            $this->minSurveillants,
            $nombreSalles,
            (int) ceil($nbCandidats / max(1, $this->ratioEtudiants))
        );

        if ($this->maxSurveillants !== null) {
            $besoin = min($besoin, max($this->minSurveillants, $this->maxSurveillants));
        }

        return $besoin;
    }

    /**
     * Besoin d'une salle : max(minimum applicable, ceil(candidats / ratio
     * applicable)). Une salle sans candidat ne requiert aucun surveillant
     * (0) — le plancher global s'applique ensuite au niveau de l'épreuve.
     *
     * Ordre de résolution sans ambiguïté (source unique) :
     * 1. règle explicite valide de la salle (non null, >= 1) ;
     * 2. règle grande salle de la configuration (faculté/session/système) si seuil atteint ;
     * 3. règle générale de la configuration (faculté/session/système).
     */
    public function besoinSalle(
        int $candidatsSalle,
        ?int $minimumSalle = null,
        ?int $ratioSalle = null,
        ?int $capaciteSalle = null
    ): int {
        if ($candidatsSalle <= 0) {
            return 0;
        }

        if ($minimumSalle !== null && $minimumSalle >= 1) {
            $minimum = $minimumSalle;
        } elseif (
            $this->seuilGrandeSalle !== null
            && $this->minSurveillantsGrandeSalle !== null
            && ($candidatsSalle >= $this->seuilGrandeSalle || ($capaciteSalle !== null && $capaciteSalle >= $this->seuilGrandeSalle))
        ) {
            $minimum = max($this->minParSalle, $this->minSurveillantsGrandeSalle);
        } else {
            $minimum = $this->minParSalle;
        }

        $ratio = ($ratioSalle !== null && $ratioSalle >= 1) ? $ratioSalle : max(1, $this->ratioEtudiants);

        return max($minimum, (int) ceil($candidatsSalle / $ratio));
    }

    public function sourceRegleSalle(
        int $candidatsSalle,
        ?int $minimumSalle = null,
        ?int $ratioSalle = null,
        ?int $capaciteSalle = null
    ): string {
        if (($minimumSalle !== null && $minimumSalle >= 1) || ($ratioSalle !== null && $ratioSalle >= 1)) {
            return 'salle';
        }

        if (
            $this->seuilGrandeSalle !== null
            && $this->minSurveillantsGrandeSalle !== null
            && ($candidatsSalle >= $this->seuilGrandeSalle || ($capaciteSalle !== null && $capaciteSalle >= $this->seuilGrandeSalle))
        ) {
            return 'grande_salle';
        }

        return 'generale';
    }

    /**
     * Besoin global d'une épreuve à partir des besoins par salle.
     *
     * @param  array<int, array{salle_id: int, candidats: int, minimum_salle?: ?int, ratio_salle?: ?int, capacite_salle?: ?int}>  $salles
     */
    public function besoinActifsParSalles(int $nbCandidats, array $salles): int
    {
        if ($salles === []) {
            return $this->besoinActifs($nbCandidats, 1);
        }

        $somme = 0;
        foreach ($salles as $salle) {
            $somme += $this->besoinSalle(
                (int) ($salle['candidats'] ?? 0),
                isset($salle['minimum_salle']) ? (int) $salle['minimum_salle'] : null,
                isset($salle['ratio_salle']) ? (int) $salle['ratio_salle'] : null,
                isset($salle['capacite_salle']) ? (int) $salle['capacite_salle'] : null,
            );
        }

        $besoin = max(
            $this->minSurveillants,
            $somme,
            (int) ceil($nbCandidats / max(1, $this->ratioEtudiants))
        );

        if ($this->maxSurveillants !== null) {
            $besoin = min($besoin, max($this->minSurveillants, $this->maxSurveillants));
        }

        return $besoin;
    }

    public function limiteJoursStricte(): bool
    {
        return $this->modeLimite === 'strict';
    }

    public function poidsChargeTotaleEffectif(): float
    {
        return $this->actifChargeTotale ? $this->poidsChargeTotale : 0.0;
    }

    public function poidsChargeJourEffectif(): float
    {
        return $this->actifChargeJour ? $this->poidsChargeJour : 0.0;
    }

    public function poidsGardesEffectif(): float
    {
        return $this->actifGardes ? $this->poidsGardes : 0.0;
    }

    public function penaliteDepassementEffectif(): float
    {
        return $this->actifLimiteJours ? $this->penaliteDepassementJours : 0.0;
    }

    public function penaliteDispersionEffectif(): float
    {
        return $this->actifDispersion ? $this->penaliteDispersion : 0.0;
    }

    public function penaliteNouveauJourEffectif(): float
    {
        return $this->actifNouveauJour ? $this->penaliteNouveauJour : 0.0;
    }

    public function poidsChargeEnseignementEffectif(): float
    {
        return $this->actifChargeEnseignement ? $this->poidsChargeEnseignement : 0.0;
    }
}
