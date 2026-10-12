<?php

namespace App\Services;

use App\Models\InscriptionAcademique;
use App\Models\Semestre;
use Illuminate\Support\Collection;

/**
 * Service de calcul académique — moyennes, crédits et décisions.
 *
 * Toutes les moyennes sont pondérées par coefficient :
 *     moyenne = Σ(note × coefficient) / Σ(coefficient)
 *
 * Précision : les calculs sont effectués en centièmes entiers
 * (note×100 et coefficient×100) afin d'éviter les erreurs de flottants
 * sur des valeurs académiques à deux décimales.
 */
class AcademicCalculationService
{
    /** Moyenne minimale de validation. */
    public const SEUIL_VALIDATION = 10.0;

    /** Moyenne minimale de rattrapage (en dessous : non validé). */
    public const SEUIL_RATTRAPAGE = 8.0;

    /**
     * Moyenne pondérée par coefficients d'un ensemble de notes.
     * Retourne null si aucune note exploitable.
     */
    public function moyennePonderee(iterable $notes): ?float
    {
        $numerateur = 0;
        $denominateur = 0;

        foreach ($notes as $note) {
            $noteCentiemes = (int) round(((float) $note['note']) * 100);
            $coefCentiemes = (int) round(((float) $note['coefficient']) * 100);

            if ($coefCentiemes <= 0 || $noteCentiemes < 0) {
                continue;
            }

            $numerateur += $noteCentiemes * $coefCentiemes;
            $denominateur += $coefCentiemes;
        }

        if ($denominateur === 0) {
            return null;
        }

        $moyenneCentiemes = (int) round($numerateur / $denominateur);

        return $moyenneCentiemes / 100;
    }

    /**
     * Notes « validées » par matière : la session de rattrapage remplace
     * la note normale lorsqu'elle existe (une seule note retenue par matière).
     *
     * @return Collection<int, array{note: float, coefficient: float, credits: int, matiere_id: int}>
     */
    public function notesValideesParMatiere(Collection $notes): Collection
    {
        return $notes
            ->groupBy('matiere_id')
            ->map(function (Collection $notesMatiere) {
                $rattrapage = $notesMatiere->firstWhere('session', 'rattrapage');

                return $rattrapage ?? $notesMatiere->firstWhere('session', 'normale');
            })
            ->filter()
            ->values();
    }

    /**
     * Crédits obtenus : crédits des matières dont la note validée est >= 10.
     */
    public function creditsObtenus(iterable $notesValidees): int
    {
        $credits = 0;

        foreach ($notesValidees as $note) {
            if ((float) $note['note'] >= self::SEUIL_VALIDATION) {
                $credits += (int) $note['credits'];
            }
        }

        return $credits;
    }

    /**
     * Décision d'un semestre à partir de sa moyenne.
     */
    public function decisionSemestre(?float $moyenne): ?string
    {
        return $this->decisionPourMoyenne($moyenne, 'Validé', 'Rattrapage', 'Non validé');
    }

    /**
     * Décision annuelle : combine la moyenne et la validation des crédits.
     */
    public function decisionAnnuelle(?float $moyenne, int $creditsObtenus, int $creditsTotal): ?string
    {
        if ($moyenne === null) {
            return null;
        }

        if ($moyenne >= self::SEUIL_VALIDATION) {
            return $creditsTotal > 0 && $creditsObtenus >= $creditsTotal
                ? 'Admis(e)'
                : 'Admis(e) — dettes de crédits';
        }

        if ($moyenne >= self::SEUIL_RATTRAPAGE) {
            return 'Ajourné(e) — rattrapage';
        }

        return 'Ajourné(e)';
    }

    /**
     * Recalcule et persiste le semestre (moyenne, crédits, décision).
     */
    public function recalculerSemestre(Semestre $semestre): void
    {
        $notes = $semestre->notes()->get();
        $validees = $this->notesValideesParMatiere($notes);

        $moyenne = $this->moyennePonderee($validees);
        $creditsObtenus = $this->creditsObtenus($validees);
        $creditsTotal = $validees->sum('credits');

        $semestre->update([
            'moyenne' => $moyenne,
            'credits_obtenus' => $creditsObtenus,
            'credits_total' => $creditsTotal,
            'decision' => $this->decisionSemestre($moyenne),
        ]);
    }

    /**
     * Recalcule et persiste une inscription académique complète :
     * chacun de ses semestres, puis l'année (moyenne annuelle, crédits, décision).
     */
    public function recalculerInscription(InscriptionAcademique $inscription): void
    {
        $semestres = $inscription->semestres()->get();

        foreach ($semestres as $semestre) {
            $this->recalculerSemestre($semestre);
        }

        $semestres = $semestres->fresh();
        $moyennes = $semestres->pluck('moyenne')->filter();

        $moyenneAnnuelle = $this->moyennePonderee(
            $semestres
                ->filter(fn (Semestre $s) => $s->moyenne !== null)
                ->map(fn (Semestre $s) => [
                    'note' => (float) $s->moyenne,
                    // pondération des semestres par leurs crédits totaux
                    'coefficient' => max(1, $s->credits_total),
                    'credits' => $s->credits_obtenus,
                ])
        );

        $inscription->update([
            'moyenne_annuelle' => $moyennes->isNotEmpty() ? $moyenneAnnuelle : null,
            'credits_obtenus' => (int) $semestres->sum('credits_obtenus'),
            'credits_total' => (int) $semestres->sum('credits_total'),
            'decision' => $moyennes->isNotEmpty()
                ? $this->decisionAnnuelle(
                    $moyenneAnnuelle,
                    (int) $semestres->sum('credits_obtenus'),
                    (int) $semestres->sum('credits_total'),
                )
                : null,
        ]);
    }

    /**
     * Moyenne générale pondérée d'un parcours : chaque année universitaire
     * est pondérée par ses crédits totaux.
     */
    public function moyenneGenerale(Collection $inscriptions): ?float
    {
        $anneesNotees = $inscriptions->filter(fn (InscriptionAcademique $i) => $i->moyenne_annuelle !== null);

        if ($anneesNotees->isEmpty()) {
            return null;
        }

        return $this->moyennePonderee(
            $anneesNotees->map(fn (InscriptionAcademique $i) => [
                'note' => (float) $i->moyenne_annuelle,
                'coefficient' => max(1, $i->credits_total),
                'credits' => $i->credits_obtenus,
            ])
        );
    }

    /**
     * Synthèse d'un dossier : moyenne générale, crédits cumulés, années validées.
     */
    public function synthese(Collection $inscriptions): array
    {
        return [
            'moyenne_generale' => $this->moyenneGenerale($inscriptions),
            'credits_obtenus' => (int) $inscriptions->sum('credits_obtenus'),
            'credits_total' => (int) $inscriptions->sum('credits_total'),
            'annees_validees' => $inscriptions->where('statut', 'valide')->count(),
            'annees_total' => $inscriptions->count(),
        ];
    }

    private function decisionPourMoyenne(?float $moyenne, string $admis, string $rattrapage, string $ajourne): ?string
    {
        if ($moyenne === null) {
            return null;
        }

        if ($moyenne >= self::SEUIL_VALIDATION) {
            return $admis;
        }

        return $moyenne >= self::SEUIL_RATTRAPAGE ? $rattrapage : $ajourne;
    }
}
