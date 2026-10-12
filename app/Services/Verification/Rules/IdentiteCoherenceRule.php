<?php

namespace App\Services\Verification\Rules;

use App\Models\Etudiant;
use App\Services\NormalisationService;
use App\Services\Verification\Anomalie;
use App\Services\Verification\VerificationRule;

/**
 * R2 — Vérifie la cohérence des données d'identité (formats, plages).
 *
 * Réutilise NormalisationService pour valider les formats.
 */
class IdentiteCoherenceRule implements VerificationRule
{
    public function __construct(
        private readonly NormalisationService $normalisation,
    ) {}

    public function code(): string
    {
        return 'identite.coherence';
    }

    public function libelle(): string
    {
        return 'Cohérence de l\'identité';
    }

    public function categorie(): string
    {
        return 'identite';
    }

    public function verifier(Etudiant $etudiant): array
    {
        $anomalies = [];

        // Format CIN : 8 chiffres (tunisien).
        if ($etudiant->cin !== null && $etudiant->cin !== '') {
            $cinNormalise = $this->normalisation->cin($etudiant->cin);

            if ($cinNormalise !== null && ! preg_match('/^\d{8}$/', $cinNormalise)) {
                $anomalies[] = new Anomalie(
                    regle: $this->code(),
                    categorie: $this->categorie(),
                    gravite: 'avertissement',
                    message: 'Format CIN non standard (attendu : 8 chiffres).',
                    justification: 'La valeur normalisée ne correspond pas au format 8 chiffres tunisien.',
                    recommandation: 'Vérifier le numéro CIN.',
                    champ: 'cin',
                );
            }
        }

        // Email valide.
        if ($etudiant->email !== null && $etudiant->email !== '') {
            if (filter_var($etudiant->email, FILTER_VALIDATE_EMAIL) === false) {
                $anomalies[] = new Anomalie(
                    regle: $this->code(),
                    categorie: $this->categorie(),
                    gravite: 'avertissement',
                    message: 'Adresse email invalide.',
                    justification: 'Le format de l\'adresse email ne passe pas la validation RFC.',
                    recommandation: 'Corriger l\'adresse email.',
                    champ: 'email',
                );
            }
        }

        // Téléphone normalisable.
        if ($etudiant->telephone !== null && $etudiant->telephone !== '') {
            $telNormalise = $this->normalisation->telephone($etudiant->telephone);

            if ($telNormalise === null) {
                $anomalies[] = new Anomalie(
                    regle: $this->code(),
                    categorie: $this->categorie(),
                    gravite: 'avertissement',
                    message: 'Numéro de téléphone non normalisable.',
                    justification: 'Le numéro de téléphone ne produit aucun chiffre après normalisation.',
                    recommandation: 'Vérifier le numéro de téléphone.',
                    champ: 'telephone',
                );
            }
        }

        // Date de naissance cohérente (âge 15-80 ans).
        if ($etudiant->date_naissance !== null) {
            $age = $etudiant->date_naissance->diffInYears(now());

            if ($age < 15) {
                $anomalies[] = new Anomalie(
                    regle: $this->code(),
                    categorie: $this->categorie(),
                    gravite: 'erreur',
                    message: 'Âge improbable : moins de 15 ans.',
                    justification: "Âge calculé : {$age} ans.",
                    recommandation: 'Vérifier la date de naissance.',
                    champ: 'date_naissance',
                );
            } elseif ($age > 80) {
                $anomalies[] = new Anomalie(
                    regle: $this->code(),
                    categorie: $this->categorie(),
                    gravite: 'avertissement',
                    message: 'Âge inhabituel : plus de 80 ans.',
                    justification: "Âge calculé : {$age} ans.",
                    recommandation: 'Vérifier la date de naissance.',
                    champ: 'date_naissance',
                );
            }

            if ($etudiant->date_naissance->isFuture()) {
                $anomalies[] = new Anomalie(
                    regle: $this->code(),
                    categorie: $this->categorie(),
                    gravite: 'bloquant',
                    message: 'Date de naissance dans le futur.',
                    justification: 'La date de naissance est postérieure à aujourd\'hui.',
                    recommandation: 'Corriger la date de naissance.',
                    champ: 'date_naissance',
                );
            }
        }

        return $anomalies;
    }
}
