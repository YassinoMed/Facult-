<?php

namespace App\Modules\ParcoursAcademique\Services;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\Note;
use App\Modules\Evaluation\Services\CalculEvaluationService;
use App\Services\AcademicCalculationService;

/**
 * Centralise le calcul et le suivi des crédits ECTS (§6).
 * Source unique : notes officielles + décisions publiées.
 * Délègue aux moteurs existants, aucun double calcul divergent :
 * moyennes via AcademicCalculationService, UE via
 * CalculEvaluationService. Équivalences/dispenses = simulations
 * uniquement, jamais comptées dans l'officiel.
 */
class CreditParcoursService
{
    public function __construct(
        private readonly AcademicCalculationService $coeur,
        private readonly CalculEvaluationService $evaluation,
    ) {}

    /**
     * Source de vérité unique : notes officielles recalculées via les
     * moteurs existants (jamais les colonnes persistées, qui ne sont
     * à jour qu'après recalcul). Le rattrapage remplace la normale
     * (zéro double comptage).
     *
     * @return array{obtenus: int, requis: int, restants: int, par_semestre: array, par_annee: array, par_ue: array, dettes_credits: int, historique: array, moyenne_generale: ?float}
     */
    public function creditsEtudiant(Etudiant $etudiant): array
    {
        $inscriptions = InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->with(['semestres', 'anneeUniversitaire', 'programmeRef', 'niveauRef'])
            ->orderBy('annee_universitaire_id')
            ->get();

        $parSemestre = [];
        $parAnnee = [];
        $parUe = [];
        $historique = [];
        $obtenusCalcule = 0;
        $totalCalcule = 0;
        $moyennesAnnuelles = [];

        foreach ($inscriptions as $inscription) {
            $synthese = $this->evaluation->syntheseEtudiant(
                $etudiant,
                (int) $inscription->annee_universitaire_id,
                $inscription->programme_id
            );

            $obtenusCalcule += $synthese['credits_obtenus'];
            $totalCalcule += $synthese['credits_total'];

            if ($synthese['moyenne'] !== null) {
                $moyennesAnnuelles[] = [
                    'note' => (float) $synthese['moyenne'],
                    'coefficient' => max(1, $synthese['credits_total']),
                    'credits' => $synthese['credits_obtenus'],
                ];
            }

            $parAnnee[] = [
                'annee' => $inscription->anneeUniversitaire?->libelle,
                'niveau' => $inscription->niveauRef?->code ?? $inscription->niveau,
                'obtenus' => $synthese['credits_obtenus'],
                'total' => $synthese['credits_total'],
                'moyenne' => $synthese['moyenne'],
                'decision' => $synthese['decision_proposee'],
            ];

            foreach ($synthese['detail_ues'] as $ue) {
                $cle = $ue['ue_id'] === null ? 'sans_ue' : 'ue_'.$ue['ue_id'];
                $parUe[$cle] = [
                    'ue_id' => $ue['ue_id'],
                    'moyenne' => $ue['moyenne'],
                    'validee' => $ue['validee'],
                    'credits' => $ue['credits'],
                ];
            }

            foreach ($inscription->semestres as $semestre) {
                $notesSemestre = Note::where('semestre_id', $semestre->id)->get();
                $validees = $this->coeur->notesValideesParMatiere($notesSemestre);
                $parSemestre[] = [
                    'annee' => $inscription->anneeUniversitaire?->libelle,
                    'semestre' => $semestre->numero,
                    'obtenus' => $this->coeur->creditsObtenus($validees),
                    'total' => $validees->sum('credits'),
                    'moyenne' => $this->coeur->moyennePonderee($validees),
                    'decision' => $semestre->decision,
                ];
            }

            $historique[] = [
                'annee' => $inscription->anneeUniversitaire?->libelle,
                'obtenus' => $synthese['credits_obtenus'],
                'total' => $synthese['credits_total'],
                'cumul_obtenus' => 0, // complété ci-dessous
            ];
        }

        $cumul = 0;
        foreach ($historique as $i => $ligne) {
            $cumul += $ligne['obtenus'];
            $historique[$i]['cumul_obtenus'] = $cumul;
        }

        $requis = $this->creditsRequis($etudiant);

        return [
            'obtenus' => $obtenusCalcule,
            'requis' => $requis,
            'restants' => max(0, $requis - $obtenusCalcule),
            'par_semestre' => $parSemestre,
            'par_annee' => $parAnnee,
            'par_ue' => array_values($parUe),
            'dettes_credits' => max(0, $totalCalcule - $obtenusCalcule),
            'historique' => $historique,
            'moyenne_generale' => $this->coeur->moyennePonderee($moyennesAnnuelles),
        ];
    }

    public function creditsRequis(Etudiant $etudiant): int
    {
        $programme = $etudiant->programmeRef;

        if ($programme !== null && (int) ($programme->credits_requis ?? 0) > 0) {
            return (int) $programme->credits_requis;
        }

        $code = strtoupper((string) ($etudiant->niveauRef?->code ?? $etudiant->niveau ?? ''));

        return str_starts_with($code, 'M') ? 120 : 180;
    }
}
