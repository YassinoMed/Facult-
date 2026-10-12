<?php

namespace App\Modules\ParcoursAcademique\Services;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\Evaluation\Models\DecisionDeliberation;

/**
 * Anomalies académiques du parcours (§20) : complète le
 * VerificationEngine existant (11 règles) sans le dupliquer.
 * Classification + explication, correction via le centre de
 * résolution existant (motif obligatoire, jamais de forçage).
 */
class AnomalieParcoursService
{
    public function __construct(
        private readonly CreditParcoursService $credits,
        private readonly DetteAcademiqueService $dettes,
    ) {}

    /**
     * @return array<int, array{code: string, gravite: string, constat: string, action: string}>
     */
    public function detecter(Etudiant $etudiant): array
    {
        $anomalies = [];
        $credits = $this->credits->creditsEtudiant($etudiant);

        if ($credits['obtenus'] > $credits['requis'] + 60) {
            $anomalies[] = [
                'code' => 'CREDITS_IMPOSSIBLES',
                'gravite' => 'elevee',
                'constat' => 'Crédits obtenus ('.$credits['obtenus'].') très supérieurs au requis ('.$credits['requis'].').',
                'action' => 'Vérifier au centre de résolution (doublons de notes ?).',
            ];
        }

        $inscriptions = InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->orderBy('annee_universitaire_id')->get();

        foreach ($inscriptions as $inscription) {
            if ($inscription->programme_id !== null && $etudiant->programme_id !== null
                && (int) $inscription->programme_id !== (int) $etudiant->programme_id) {
                $anomalies[] = [
                    'code' => 'FORMATION_INCOMPATIBLE',
                    'gravite' => 'moyenne',
                    'constat' => 'Inscription '.$inscription->id.' rattachée à un autre programme que le dossier.',
                    'action' => 'Rapprocher avec la réorientation ou le transfert.',
                ];
                break;
            }
        }

        $sansDecision = $inscriptions->filter(function ($inscription) use ($etudiant) {
            return ! DecisionDeliberation::where('etudiant_id', $etudiant->id)
                ->where('inscription_academique_id', $inscription->id)
                ->where('publie', true)->exists();
        });

        if ($inscriptions->count() >= 2 && $sansDecision->count() >= 2) {
            $anomalies[] = [
                'code' => 'PROGRESSION_IMPOSSIBLE',
                'gravite' => 'moyenne',
                'constat' => $sansDecision->count().' inscriptions sans décision de jury publiée.',
                'action' => 'Préparer les délibérations manquantes avant toute progression.',
            ];
        }

        $dettes = $this->dettes->lister($etudiant);
        foreach ($dettes as $dette) {
            if ($dette['note'] !== null && $dette['note'] >= 10.0) {
                $anomalies[] = [
                    'code' => 'DETTE_INCOHERENTE',
                    'gravite' => 'faible',
                    'constat' => 'Dette sur « '.$dette['matiere'].' » malgré une note ≥ 10.',
                    'action' => 'Recalculer la synthèse (compensation UE ?).',
                ];
                break;
            }
        }

        $admisSansCredits = DecisionDeliberation::where('etudiant_id', $etudiant->id)
            ->where('publie', true)->whereIn('decision', ['admis', 'admis_compensation'])
            ->where('credits_obtenus', 0)->exists();
        if ($admisSansCredits) {
            $anomalies[] = [
                'code' => 'DECISION_INCOMPATIBLE',
                'gravite' => 'elevee',
                'constat' => 'Décision admis* publiée avec 0 crédit.',
                'action' => 'Re-préparer la délibération concernée.',
            ];
        }

        return $anomalies;
    }
}
