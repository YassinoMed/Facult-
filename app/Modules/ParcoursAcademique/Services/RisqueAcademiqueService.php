<?php

namespace App\Modules\ParcoursAcademique\Services;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\ParcoursAcademique\Models\ParcoursAlerteRisque;
use App\Services\AuditService;

/**
 * Détection des risques académiques (§12) : accumulation de
 * dettes, faibles moyennes, semestres non validés, lenteur,
 * crédits insuffisants, échecs répétés, UE importantes,
 * redoublement, non-progression. Niveaux faible/moyen/élevé/
 * critique + explication. Jamais de décision automatique.
 */
class RisqueAcademiqueService
{
    public function __construct(
        private readonly CreditParcoursService $credits,
        private readonly DetteAcademiqueService $dettes,
        private readonly ParcoursRegleService $regles,
        private readonly AuditService $audit,
    ) {}

    /**
     * @return array{niveau: string, score: int, motifs: array<int, string>, detail: array<string, mixed>}
     */
    public function evaluer(Etudiant $etudiant, ?InscriptionAcademique $source = null): array
    {
        $regle = $this->regles->resoudre('RISQUE');
        $seuils = $regle['criteres']['seuils'] ?? ['faible' => 0, 'moyen' => 3, 'eleve' => 6, 'critique' => 9];

        $credits = $this->credits->creditsEtudiant($etudiant);
        $dettes = $this->dettes->lister($etudiant);
        $inscriptions = InscriptionAcademique::where('etudiant_id', $etudiant->id)->orderBy('annee_universitaire_id')->get();

        $motifs = [];
        $score = 0;

        $dettesActives = collect($dettes)->where('repassable', true)->count();
        if ($dettesActives >= 5) {
            $score += 4;
            $motifs[] = "Accumulation de dettes : {$dettesActives} matières à repasser.";
        } elseif ($dettesActives >= 3) {
            $score += 2;
            $motifs[] = "Dettes multiples : {$dettesActives} matières non validées.";
        } elseif ($dettesActives >= 1) {
            $score += 1;
            $motifs[] = "{$dettesActives} dette(s) à reprendre.";
        }

        if ($credits['moyenne_generale'] !== null && $credits['moyenne_generale'] < 8.0) {
            $score += 3;
            $motifs[] = 'Moyenne générale faible ('.$credits['moyenne_generale'].').';
        } elseif ($credits['moyenne_generale'] !== null && $credits['moyenne_generale'] < 10.0) {
            $score += 2;
            $motifs[] = 'Moyenne générale sous le seuil ('.$credits['moyenne_generale'].').';
        }

        $nonValides = collect($credits['par_semestre'])->filter(fn ($s) => $s['moyenne'] !== null && $s['moyenne'] < 10.0)->count();
        if ($nonValides >= 2) {
            $score += 2;
            $motifs[] = "{$nonValides} semestres non validés.";
        }

        if ($inscriptions->count() >= 4 && $credits['obtenus'] < 120) {
            $score += 2;
            $motifs[] = 'Progression lente : '.$credits['obtenus'].' crédits en '.$inscriptions->count().' inscriptions.';
        }

        if ($credits['restants'] > 120) {
            $score += 1;
            $motifs[] = 'Crédits restants importants ('.$credits['restants'].').';
        }

        $niveau = 'faible';
        foreach (['critique' => 9, 'eleve' => 6, 'moyen' => 3] as $cle => $seuil) {
            $limite = $seuils[$cle] ?? $seuil;
            if ($score >= $limite) {
                $niveau = $cle;
                break;
            }
        }

        if ($niveau !== 'faible' && $motifs === []) {
            $motifs[] = 'Signaux faibles cumulés sous les seuils unitaires.';
        }

        return [
            'niveau' => $niveau,
            'score' => $score,
            'motifs' => $motifs,
            'detail' => [
                'dettes' => $dettesActives,
                'moyenne_generale' => $credits['moyenne_generale'],
                'semestres_non_valides' => $nonValides,
                'credits_obtenus' => $credits['obtenus'],
                'regle' => $regle['reference'],
            ],
        ];
    }

    public function enregistrer(Etudiant $etudiant, ?InscriptionAcademique $source = null): ParcoursAlerteRisque
    {
        $evaluation = $this->evaluer($etudiant, $source);

        $alerte = ParcoursAlerteRisque::create([
            'etudiant_id' => $etudiant->id,
            'inscription_id' => $source?->id,
            'niveau' => $evaluation['niveau'],
            'score' => $evaluation['score'],
            'motifs' => $evaluation['motifs'],
            'calcule_le' => now(),
            'statut' => 'nouvelle',
        ]);

        $this->audit->tracer($etudiant, 'parcours.risque_calcule', 'parcours_alerte', $alerte->id, null, [
            'niveau' => $evaluation['niveau'],
            'score' => $evaluation['score'],
        ]);

        return $alerte;
    }
}
