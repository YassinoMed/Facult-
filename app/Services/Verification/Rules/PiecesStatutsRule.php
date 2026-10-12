<?php

namespace App\Services\Verification\Rules;

use App\Models\Etudiant;
use App\Services\DocumentsRequisService;
use App\Services\Verification\Anomalie;
use App\Services\Verification\VerificationRule;

/**
 * R10 — États des pièces GED : expirées, rejetées ou en attente de
 * contrôle (lecture seule, informative). La matrice par type donne le
 * référentiel ; sans matrice, aucune anomalie (transition préservée).
 */
class PiecesStatutsRule implements VerificationRule
{
    public function __construct(private readonly DocumentsRequisService $exigences) {}

    public function code(): string
    {
        return 'documents.statuts';
    }

    public function libelle(): string
    {
        return 'États des pièces';
    }

    public function categorie(): string
    {
        return 'document';
    }

    public function verifier(Etudiant $etudiant): array
    {
        if ($etudiant->statut !== 'valide') {
            return [];
        }

        $type = $etudiant->inscriptionsAcademiques()->latest('annee_universitaire_id')->first()?->type;

        if ($this->exigences->requisPour($type) === []) {
            return [];
        }

        $bilan = $this->exigences->verifier($etudiant, $type);
        $anomalies = [];

        foreach (['expires' => 'expirée', 'rejetes' => 'rejetée', 'en_attente' => 'en attente de contrôle'] as $cle => $libelle) {
            foreach ($bilan[$cle] as $code) {
                $anomalies[] = new Anomalie(
                    regle: $this->code(),
                    categorie: $this->categorie(),
                    gravite: 'avertissement',
                    message: "Pièce « {$code} » {$libelle}.",
                    justification: "GED : statut non valide pour « {$code} » (inscription {$type}).",
                    recommandation: "Déposer une nouvelle version de « {$code} » puis la faire valider.",
                    donnees: ['document_type' => $code, 'etat' => $cle],
                    champ: 'document_type',
                );
            }
        }

        return $anomalies;
    }
}
