<?php

namespace App\Services\Verification;

use App\Models\DossierVerification;
use App\Models\Etudiant;

/**
 * LOOP vérificateur §13 : persistance des exécutions (append-only).
 */
final class VerificationStore
{
    public function enregistrer(Etudiant $etudiant, VerificationResult $resultat, string $source, ?int $auteurId = null): DossierVerification
    {
        return DossierVerification::create([
            'etudiant_id' => $etudiant->id,
            'verdict' => $resultat->verdict,
            'score' => $resultat->score,
            'regles_verifiees' => $resultat->rulesChecked,
            'regles_reussies' => $resultat->rulesPassed,
            // LOOP C : codes des règles en échec (uniques, ordre stable) —
            // source de la catégorisation dérivée de la file, sans PII.
            'regles_echouees' => collect($resultat->anomalies)
                ->map(fn ($anomalie) => $anomalie->regle)
                ->unique()
                ->values()
                ->all(),
            'anomalies_count' => count($resultat->anomalies),
            'duree_ms' => $resultat->dureeMs,
            'source' => $source,
            'auteur_id' => $auteurId,
        ]);
    }
}
