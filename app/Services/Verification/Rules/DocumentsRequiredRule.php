<?php

namespace App\Services\Verification\Rules;

use App\Models\Etudiant;
use App\Services\Verification\Anomalie;
use App\Services\Verification\VerificationRule;

/**
 * R5 — Vérifie la présence des documents requis.
 *
 * Un étudiant validé devrait avoir au minimum certains documents
 * dans sa GED (documents_academiques).
 */
class DocumentsRequiredRule implements VerificationRule
{
    /** Types de documents attendus pour un étudiant validé. */
    private const TYPES_REQUIS_VALIDE = ['cin', 'photo', 'baccalaureat'];

    public function code(): string
    {
        return 'documents.requis';
    }

    public function libelle(): string
    {
        return 'Documents requis';
    }

    public function categorie(): string
    {
        return 'document';
    }

    public function verifier(Etudiant $etudiant): array
    {
        $anomalies = [];

        // Seuls les étudiants validés sont soumis à cette vérification.
        if ($etudiant->statut !== 'valide') {
            return $anomalies;
        }

        $documents = $etudiant->documentsAcademiques()->pluck('type_document')->map(fn ($t) => mb_strtolower($t))->unique();

        if ($documents->isEmpty()) {
            $anomalies[] = new Anomalie(
                regle: $this->code(),
                categorie: $this->categorie(),
                gravite: 'avertissement',
                message: 'Aucun document académique dans le dossier.',
                justification: 'Table documents_academiques : 0 enregistrement pour cet étudiant.',
                recommandation: 'Téléverser les documents requis (CIN, photo, baccalauréat).',
            );

            return $anomalies;
        }

        foreach (self::TYPES_REQUIS_VALIDE as $type) {
            if (! $documents->contains($type)) {
                $anomalies[] = new Anomalie(
                    regle: $this->code(),
                    categorie: $this->categorie(),
                    gravite: 'avertissement',
                    message: "Document « {$type} » manquant.",
                    justification: "Aucun document de type « {$type} » trouvé dans la GED.",
                    recommandation: "Téléverser le document « {$type} ».",
                    donnees: ['type_manquant' => $type, 'types_presents' => $documents->values()->all()],
                    champ: 'type_document',
                );
            }
        }

        return $anomalies;
    }
}
