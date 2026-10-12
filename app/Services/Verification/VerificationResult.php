<?php

namespace App\Services\Verification;

/**
 * Résultat standardisé d'une vérification de dossier étudiant.
 *
 * Verdict déterminé par la gravité la plus élevée :
 * - CONFORME  : aucune anomalie
 * - A_VERIFIER : au moins 1 'avertissement' ou 'erreur'
 * - BLOQUANT   : au moins 1 'bloquant'
 *
 * Inclut le score de conformité (sur 100) et les métriques de règles.
 */
readonly class VerificationResult
{
    public const CONFORME = 'CONFORME';

    public const A_VERIFIER = 'A_VERIFIER';

    public const BLOQUANT = 'BLOQUANT';

    public int $score;

    public int $rulesChecked;

    public int $rulesPassed;

    /**
     * @param  string  $verdict  CONFORME | A_VERIFIER | BLOQUANT
     * @param  array<int, Anomalie>  $anomalies  Anomalies détectées
     * @param  array<int, string>  $reglesExecutees  Codes des règles exécutées
     * @param  float  $dureeMs  Temps d'exécution en millisecondes
     * @param  int  $etudiantId  ID de l'étudiant vérifié
     */
    public function __construct(
        public string $verdict,
        public array $anomalies,
        public array $reglesExecutees,
        public float $dureeMs,
        public int $etudiantId,
    ) {
        $this->rulesChecked = count($reglesExecutees);

        $reglesEnErreur = [];
        foreach ($anomalies as $a) {
            $reglesEnErreur[$a->regle] = true;
        }
        $this->rulesPassed = max(0, $this->rulesChecked - count($reglesEnErreur));

        // Calcul du score explicable (100 - pénalités selon gravité)
        $penalites = 0;
        foreach ($anomalies as $a) {
            $penalites += match ($a->gravite) {
                'bloquant' => 35,
                'erreur' => 15,
                'avertissement' => 5,
                default => 1,
            };
        }

        $calcule = max(0, min(100, 100 - $penalites));
        // Règle §12 : Si un blocage existe, le score ne peut pas dépasser 49
        if ($this->verdict === self::BLOQUANT && $calcule > 49) {
            $calcule = 49;
        }
        $this->score = $calcule;
    }

    public function estConforme(): bool
    {
        return $this->verdict === self::CONFORME;
    }

    public function estBloquant(): bool
    {
        return $this->verdict === self::BLOQUANT;
    }

    public function nombreAnomalies(): int
    {
        return count($this->anomalies);
    }

    public function nombreBloquants(): int
    {
        return count(array_filter($this->anomalies, fn ($a) => $a->gravite === 'bloquant'));
    }

    public function nombreErreurs(): int
    {
        return count(array_filter($this->anomalies, fn ($a) => $a->gravite === 'erreur'));
    }

    public function nombreAvertissements(): int
    {
        return count(array_filter($this->anomalies, fn ($a) => $a->gravite === 'avertissement'));
    }

    /** Anomalies groupées par catégorie. */
    public function parCategorie(): array
    {
        $groupees = [];

        foreach ($this->anomalies as $anomalie) {
            $groupees[$anomalie->categorie][] = $anomalie;
        }

        return $groupees;
    }

    /** Anomalies groupées par gravité. */
    public function parGravite(): array
    {
        $groupees = [];

        foreach ($this->anomalies as $anomalie) {
            $groupees[$anomalie->gravite][] = $anomalie;
        }

        return $groupees;
    }

    /** Calcule le verdict depuis une liste d'anomalies. */
    public static function calculerVerdict(array $anomalies): string
    {
        $maxGravite = 0;

        foreach ($anomalies as $anomalie) {
            $maxGravite = max($maxGravite, Anomalie::GRAVITES[$anomalie->gravite] ?? 0);
        }

        return match (true) {
            $maxGravite >= Anomalie::GRAVITES['bloquant'] => self::BLOQUANT,
            $maxGravite >= Anomalie::GRAVITES['avertissement'] => self::A_VERIFIER,
            default => self::CONFORME,
        };
    }

    /** Structure standardisée (§13). */
    public function toArray(): array
    {
        return [
            'status' => $this->verdict,
            'verdict' => $this->verdict,
            'score' => $this->score,
            'rules_checked' => $this->rulesChecked,
            'rules_passed' => $this->rulesPassed,
            'anomalies_count' => count($this->anomalies),
            'anomalies' => array_map(fn (Anomalie $a) => [
                'code' => $a->regle,
                'regle' => $a->regle,
                'categorie' => $a->categorie,
                'gravite' => $a->gravite,
                'severity' => match ($a->gravite) {
                    'bloquant' => 'BLOCKING',
                    'erreur' => 'HIGH',
                    'avertissement' => 'MEDIUM',
                    default => 'INFO',
                },
                'message' => $a->message,
                'justification' => $a->justification,
                'recommandation' => $a->recommandation,
                'champ' => $a->champ,
                'donnees' => $a->donnees,
            ], $this->anomalies),
            'recommendations' => array_values(array_unique(array_filter(array_map(fn (Anomalie $a) => $a->recommandation, $this->anomalies)))),
            'regles_executees' => $this->reglesExecutees,
            'duree_ms' => $this->dureeMs,
            'student_id' => $this->etudiantId,
        ];
    }
}
