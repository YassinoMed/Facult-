<?php

namespace App\Services\Verification;

/**
 * Anomalie détectée par une règle de vérification (§ vérificateur).
 *
 * Valeur immutable — jamais persistée directement, c'est
 * l'agrégateur qualité qui décide de la persistence.
 * Les données ne contiennent AUCUNE PII loguée : elles sont
 * affichées côté admin uniquement, jamais écrites dans les logs.
 */
readonly class Anomalie
{
    /**
     * @param  string  $regle  Code de la règle (ex. 'identite.complete')
     * @param  string  $categorie  Catégorie métier
     * @param  string  $gravite  'info' | 'avertissement' | 'erreur' | 'bloquant'
     * @param  string  $message  Description du problème
     * @param  string  $justification  Preuve technique (sans PII)
     * @param  string  $recommandation  Action suggérée
     * @param  array  $donnees  Données contrôlées (pour preuve admin)
     * @param  ?string  $champ  Champ concerné (nullable)
     */
    public function __construct(
        public string $regle,
        public string $categorie,
        public string $gravite,
        public string $message,
        public string $justification,
        public string $recommandation,
        public array $donnees = [],
        public ?string $champ = null,
    ) {}

    /** Gravités ordonnées (la plus sévère = indice le plus élevé). */
    public const GRAVITES = ['info' => 0, 'avertissement' => 1, 'erreur' => 2, 'bloquant' => 3];

    public function estBloquante(): bool
    {
        return $this->gravite === 'bloquant';
    }

    /** Conversion vers le format attendu par AgregateurQualite. */
    public function versFormatQualite(int $etudiantId, string $numInscription): array
    {
        return [
            'gravite' => $this->gravite === 'bloquant' ? 'critique' : $this->gravite,
            'entite' => 'Étudiant '.$numInscription,
            'lien' => route('admin.etudiants.show', $etudiantId),
            'probleme' => $this->message,
            'action' => $this->recommandation,
        ];
    }
}
