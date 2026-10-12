<?php

namespace App\Services\Affectations;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use Illuminate\Support\Collection;

/**
 * Refonte affectation §6 (étape 5) : détermine qui peut être affecté.
 * Pré-filtre en masse (eager, sans N+1) + vérification individuelle.
 * Règle documentée : dossier `valide` + inscription `en_cours/valide`
 * sur l'année + non suspendu. Les affectations verrouillées ou déjà
 * confirmées sont exclues (contraintes pour les simulations suivantes).
 */
final class AssignmentEligibilityResolver
{
    /**
     * @param  array<int>  $etudiantIds
     * @return Collection<int, Etudiant>
     */
    public function eligibles(array $etudiantIds, AnneeUniversitaire $annee): Collection
    {
        if ($etudiantIds === []) {
            return collect();
        }

        $inscriptions = InscriptionAcademique::where('annee_universitaire_id', $annee->id)
            ->whereIn('etudiant_id', $etudiantIds)
            ->whereIn('statut', ['en_cours', 'valide'])
            ->pluck('etudiant_id')
            ->all();

        return Etudiant::whereIn('id', $etudiantIds)
            ->where('statut', 'valide')
            ->whereIn('id', $inscriptions !== [] ? $inscriptions : [0])
            ->with(['situations', 'affectations'])
            ->get()
            ->reject(fn (Etudiant $e) => $e->estSuspendu())
            ->reject(fn (Etudiant $e) => $this->estFige($e, $annee))
            ->values();
    }

    public function motifIneligibilite(Etudiant $etudiant, AnneeUniversitaire $annee): ?string
    {
        if ($etudiant->statut !== 'valide') {
            return "Dossier étudiant « {$etudiant->statut} » (statut permettant l'affectation : valide).";
        }

        $inscription = InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->where('annee_universitaire_id', $annee->id)->first();

        if ($inscription === null) {
            return "Aucune inscription pour l'année {$annee->libelle}.";
        }

        if (! in_array($inscription->statut, ['en_cours', 'valide'], true)) {
            return "Inscription « {$inscription->statut} » (requise : en_cours/valide).";
        }

        if ($etudiant->estSuspendu()) {
            return 'Étudiant suspendu : affectation impossible.';
        }

        if ($this->estFige($etudiant, $annee)) {
            return 'Affectation verrouillée ou déjà confirmée : modification administrative explicite requise.';
        }

        return null;
    }

    private function estFige(Etudiant $etudiant, AnneeUniversitaire $annee): bool
    {
        $affectation = $etudiant->affectations
            ->where('annee_universitaire_id', $annee->id)
            ->where('statut', 'active')
            ->first();

        return $affectation !== null && ((bool) $affectation->verrouille || $affectation->confirme_le !== null);
    }
}
