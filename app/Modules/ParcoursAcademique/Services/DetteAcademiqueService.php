<?php

namespace App\Modules\ParcoursAcademique\Services;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Modules\Evaluation\Services\CalculEvaluationService;
use App\Modules\ParcoursAcademique\Models\ParcoursDispense;
use App\Modules\ParcoursAcademique\Models\ParcoursEquivalence;
use App\Services\AcademicCalculationService;
use Illuminate\Support\Collection;

/**
 * Dettes académiques (§7) : UE/ECUE non validées, liées à
 * l'historique original (semestre/année/note/crédits conservés).
 * Dérivées des notes officielles via les moteurs existants —
 * aucune table de dettes dupliquée. Dispenses/équivalences
 * validées = couverture affichée, dette d'origine conservée.
 */
class DetteAcademiqueService
{
    public function __construct(
        private readonly AcademicCalculationService $coeur,
        private readonly CalculEvaluationService $evaluation,
    ) {}

    /**
     * @return array<int, array{matiere_id: ?int, matiere: ?string, ue_id: ?int, semestre_origine: ?int, annee: ?string, credits: int, note: ?float, statut: string, repassable: bool, couverture: ?string, impact: string}>
     */
    public function lister(Etudiant $etudiant): array
    {
        $inscriptions = InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->with(['semestres', 'anneeUniversitaire', 'niveauRef', 'programmeRef'])
            ->orderBy('annee_universitaire_id')
            ->get();

        $dispenses = ParcoursDispense::where('etudiant_id', $etudiant->id)
            ->where('statut', 'validee')->get();
        $equivalences = ParcoursEquivalence::where('etudiant_id', $etudiant->id)
            ->where('statut', 'validee')->get();

        $dettes = [];

        foreach ($inscriptions as $inscription) {
            $notes = Note::where('inscription_academique_id', $inscription->id)
                ->with('matiere')->get();
            $validees = $this->coeur->notesValideesParMatiere($notes);

            foreach ($validees as $ligne) {
                /** @var Matiere|null $matiere */
                $matiere = $ligne['matiere'] ?? null;
                $note = (float) $ligne['note'];

                if ($note >= AcademicCalculationService::SEUIL_VALIDATION) {
                    continue;
                }

                $couverture = $this->couverture(
                    $matiere?->id, $matiere?->ue_id, $dispenses, $equivalences
                );

                $dettes[] = [
                    'matiere_id' => $matiere?->id,
                    'matiere' => $matiere?->nom,
                    'ue_id' => $matiere?->ue_id,
                    'semestre_origine' => $matiere?->semestre,
                    'annee' => $inscription->anneeUniversitaire?->libelle,
                    'credits' => (int) $ligne['credits'],
                    'note' => $note,
                    'statut' => $couverture !== null ? 'couverte_'.$couverture : 'a_repasser',
                    'repassable' => $couverture === null,
                    'couverture' => $couverture,
                    'impact' => $note >= AcademicCalculationService::SEUIL_RATTRAPAGE
                        ? 'Rattrapage possible, bloque la validation complète du semestre.'
                        : 'À revalider, bloque la progression sans compensation.',
                ];
            }
        }

        return $dettes;
    }

    /**
     * @param  Collection<int, ParcoursDispense>  $dispenses
     * @param  Collection<int, ParcoursEquivalence>  $equivalences
     */
    private function couverture(?int $matiereId, ?int $ueId, $dispenses, $equivalences): ?string
    {
        foreach ($dispenses as $dispense) {
            if ($matiereId !== null && (int) $dispense->matiere_id === $matiereId) {
                return 'dispense';
            }
            if ($ueId !== null && (int) $dispense->ue_id === $ueId) {
                return 'dispense';
            }
        }

        foreach ($equivalences as $equivalence) {
            if ($matiereId !== null && (int) $equivalence->cible_matiere_id === $matiereId) {
                return 'equivalence';
            }
            if ($ueId !== null && (int) $equivalence->cible_ue_id === $ueId) {
                return 'equivalence';
            }
        }

        return null;
    }
}
