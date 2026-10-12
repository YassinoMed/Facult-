<?php

namespace App\Modules\Evaluation\Services;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\Note;
use App\Services\AcademicCalculationService;

/**
 * Calculs centralisés de l'évaluation (§31-34) : AUCUN calcul dans
 * Blade / contrôleurs / JS. Réutilise AcademicCalculationService
 * (formule pondérée, précision centièmes) + règles versionnées.
 */
class CalculEvaluationService
{
    public function __construct(
        private AcademicCalculationService $coeur,
        private RegleEvaluationService $regles,
    ) {}

    /**
     * Moyenne d'une UE : pondérée des ECUE (notes validées par matière).
     *
     * @param  iterable<array{note: float, coefficient: float, credits: int, matiere_id: int}>  $notesEcue
     */
    public function moyenneUe(iterable $notesEcue, int $anneeId, ?int $programmeId = null, ?array $regle = null): ?float
    {
        $regle ??= $this->regles->resolve($anneeId, $programmeId);
        $moyenne = $this->coeur->moyennePonderee($notesEcue);

        return $this->regles->arrondir($moyenne, $regle['arrondi']);
    }

    /**
     * Validation d'une UE (§33) : moyenne + seuil + note éliminatoire +
     * compensation configurable. Retourne décision + crédits.
     *
     * @param  array<int, array{note: float, coefficient: float, credits: int, matiere_id: int}>  $notesEcue
     * @return array{moyenne: ?float, validee: bool, credits: int, credits_obtenus: int, motifs: array<int, string>}
     */
    public function validerUe(
        array $notesEcue,
        int $creditsUe,
        int $anneeId,
        ?int $programmeId = null,
        ?array $regle = null,
    ): array {
        $regle ??= $this->regles->resolve($anneeId, $programmeId);
        $motifs = [];

        if ($notesEcue === []) {
            return ['moyenne' => null, 'validee' => false, 'credits' => 0, 'credits_obtenus' => 0, 'motifs' => ['Aucune note.']];
        }

        if ($regle['note_eliminatoire'] !== null) {
            foreach ($notesEcue as $note) {
                if ((float) $note['note'] < $regle['note_eliminatoire']) {
                    $motifs[] = 'Note éliminatoire détectée.';

                    return [
                        'moyenne' => $this->regles->arrondir($this->coeur->moyennePonderee($notesEcue), $regle['arrondi']),
                        'validee' => false,
                        'credits' => 0,
                        'credits_obtenus' => 0,
                        'motifs' => $motifs,
                    ];
                }
            }
        }

        $moyenne = $this->regles->arrondir($this->coeur->moyennePonderee($notesEcue), $regle['arrondi']);

        if ($moyenne === null) {
            return ['moyenne' => null, 'validee' => false, 'credits' => 0, 'credits_obtenus' => 0, 'motifs' => ['Moyenne incalculable.']];
        }

        if ($moyenne >= $regle['seuil_validation']) {
            return ['moyenne' => $moyenne, 'validee' => true, 'credits' => $creditsUe, 'credits_obtenus' => $creditsUe, 'motifs' => []];
        }

        if (! $regle['compensation_ue']) {
            $motifs[] = 'Compensation UE désactivée par le règlement.';
        }

        return ['moyenne' => $moyenne, 'validee' => false, 'credits' => 0, 'credits_obtenus' => 0, 'motifs' => $motifs === [] ? ['Moyenne insuffisante.'] : $motifs];
    }

    /**
     * Synthèse d'un étudiant pour une délibération : moyennes UE,
     * crédits ECTS, semestre — à partir des notes officielles `notes`.
     *
     * @return array{moyenne: ?float, credits_obtenus: int, credits_total: int, ues_validees: int, ues_total: int, detail_ues: array<int, array{ue_id: ?int, moyenne: ?float, validee: bool, credits: int}>, decision_proposee: string}
     */
    public function syntheseEtudiant(
        Etudiant $etudiant,
        int $anneeId,
        ?int $programmeId = null,
        ?InscriptionAcademique $inscription = null,
        ?array $regle = null,
    ): array {
        $regle ??= $this->regles->resolve($anneeId, $programmeId);

        $inscription ??= InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->where('annee_universitaire_id', $anneeId)
            ->first();

        if ($inscription === null) {
            return $this->syntheseVide();
        }

        $notes = $inscription->relationLoaded('notes')
            ? $inscription->notes
            : Note::where('inscription_academique_id', $inscription->id)
                ->with('matiere')
                ->get();

        $validees = $this->coeur->notesValideesParMatiere($notes);

        $parUe = [];
        foreach ($validees as $note) {
            $ueId = $note['matiere']->ue_id ?? null;
            $parUe[$ueId ?? 0][] = $note;
        }

        $detail = [];
        $creditsObtenus = 0;
        $creditsTotal = 0;
        $uesValidees = 0;

        foreach ($parUe as $ueId => $notesUe) {
            $creditsUe = (int) collect($notesUe)->sum('credits');
            $resultat = $this->validerUe(
                collect($notesUe)->map(fn ($n) => [
                    'note' => (float) $n['note'],
                    'coefficient' => (float) $n['coefficient'],
                    'credits' => (int) $n['credits'],
                    'matiere_id' => (int) $n['matiere_id'],
                ])->all(),
                $creditsUe,
                $anneeId,
                $programmeId,
                $regle
            );

            $creditsTotal += $creditsUe;
            $creditsObtenus += $resultat['credits_obtenus'];
            $uesValidees += $resultat['validee'] ? 1 : 0;

            $detail[] = [
                'ue_id' => $ueId === 0 ? null : $ueId,
                'moyenne' => $resultat['moyenne'],
                'validee' => $resultat['validee'],
                'credits' => $resultat['credits_obtenus'],
            ];
        }

        $moyenne = $this->regles->arrondir($this->coeur->moyennePonderee($validees), $regle['arrondi']);

        return [
            'moyenne' => $moyenne,
            'credits_obtenus' => $creditsObtenus,
            'credits_total' => $creditsTotal,
            'ues_validees' => $uesValidees,
            'ues_total' => count($parUe),
            'detail_ues' => $detail,
            'decision_proposee' => $this->proposerDecision($moyenne, $creditsObtenus, $creditsTotal, $regle),
            'inscription_academique_id' => $inscription->id,
        ];
    }

    /**
     * Phase 6 §12 : la compensation semestrielle est load-bearing.
     * Moyenne au seuil mais crédits incomplets : `admis_compensation`
     * si le règlement l'autorise, sinon `ajourne` (dettes à reprendre,
     * jamais de validation implicite).
     *
     * @param  array{seuil_validation: float, seuil_rattrapage: float, compensation_semestre: bool}  $regle
     */
    private function proposerDecision(?float $moyenne, int $obtenus, int $total, array $regle): string
    {
        if ($moyenne === null) {
            return 'a_examiner';
        }

        if ($moyenne >= $regle['seuil_validation']) {
            if ($total <= 0 || $obtenus >= $total) {
                return 'admis';
            }

            return ($regle['compensation_semestre'] ?? true) ? 'admis_compensation' : 'ajourne';
        }

        return $moyenne >= $regle['seuil_rattrapage'] ? 'rattrapage' : 'ajourne';
    }

    /** @return array<mixed> */
    private function syntheseVide(): array
    {
        return [
            'moyenne' => null,
            'credits_obtenus' => 0,
            'credits_total' => 0,
            'ues_validees' => 0,
            'ues_total' => 0,
            'detail_ues' => [],
            'decision_proposee' => 'a_examiner',
            'inscription_academique_id' => null,
        ];
    }
}
