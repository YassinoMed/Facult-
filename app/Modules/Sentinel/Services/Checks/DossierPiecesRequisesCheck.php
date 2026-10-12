<?php

namespace App\Modules\Sentinel\Services\Checks;

use App\Contracts\SentinelCheckInterface;
use App\Models\Etudiant;

/**
 * Platform Sentinel — conformité des pièces justificatives obligatoires.
 * Détecte les étudiants validés sans pièces académiques rattachées.
 */
class DossierPiecesRequisesCheck implements SentinelCheckInterface
{
    public function code(): string
    {
        return 'DOSSIER_PIECES_REQUISES';
    }

    public function categorie(): string
    {
        return 'integrite';
    }

    public function gravite(): string
    {
        return 'avertissement';
    }

    public function module(): ?string
    {
        return 'dossiers';
    }

    public function controler(): array
    {
        $constats = [];

        try {
            // Étudiants validés n'ayant aucun document académique enregistré
            $etudiantsSansPiece = Etudiant::where('statut', 'valide')
                ->whereDoesntHave('documentsAcademiques')
                ->limit(20)
                ->get();

            foreach ($etudiantsSansPiece as $etudiant) {
                // PII : jamais de nom/CIN dans le titre exposé au cockpit
                // (rôle `sentinel.view` large) — fiche #id + scope faculté.
                $constats[] = [
                    'titre' => "Dossier sans pièce : étudiant #{$etudiant->id}",
                    'diagnostic' => "L'étudiant #{$etudiant->id} est au statut `valide` mais ne possède aucune pièce dans son dossier académique.",
                    'recommandation' => 'Vérifier le dossier étudiant et exiger le téléversement des pièces obligatoires (CIN, Baccalauréat).',
                    'entite_type' => 'etudiant',
                    'entite_id' => $etudiant->id,
                    'entite_libelle' => "Étudiant #{$etudiant->id}",
                    'faculte_id' => $etudiant->faculte_id,
                ];
            }
        } catch (\Throwable) {
        }

        return $constats;
    }
}
