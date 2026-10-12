<?php

namespace App\Modules\Referentiel\Services;

use App\Modules\Referentiel\Models\Programme;

/**
 * Analyse de la charge pédagogique (ENTERPRISE LOOP 08) : lecture seule,
 * indicateurs DESCRIPTIFS calculés sur les données canoniques (crédits
 * UE, volumes ECUE, types d'UE, prérequis). Aucun seuil universel de
 * surcharge : les écarts inter-semestres sont présentés tels quels, au
 * responsable de les qualifier. Sources et formules exposées avec les
 * chiffres (jamais de boîte noire).
 */
final class ChargePedagogiqueService
{
    /**
     * @return array<string, mixed>
     */
    public function analyser(Programme $programme): array
    {
        $programme->loadMissing([
            'faculte', 'typeDiplome',
            'niveaux.semestres.unitesEnseignement.ecues.prerequis',
        ]);

        $niveaux = [];
        $typesUe = [];
        $liensPrerequis = 0;
        $totaux = ['credits' => 0, 'cm' => 0, 'td' => 0, 'tp' => 0, 'ues' => 0, 'ecues' => 0];

        foreach ($programme->niveaux as $niveau) {
            $lignes = [];
            $sousTotaux = ['credits' => 0, 'cm' => 0, 'td' => 0, 'tp' => 0, 'ues' => 0, 'ecues' => 0];

            foreach ($niveau->semestres as $semestre) {
                $ues = $semestre->unitesEnseignement;
                $ecues = $ues->flatMap->ecues;
                $credits = (int) $ues->sum('credits');
                $cm = (int) $ecues->sum('volume_cm');
                $td = (int) $ecues->sum('volume_td');
                $tp = (int) $ecues->sum('volume_tp');

                foreach ($ues as $ue) {
                    $typesUe[$ue->type ?? 'non_renseigne'] = ($typesUe[$ue->type ?? 'non_renseigne'] ?? 0) + 1;
                }
                $liensPrerequis += $ecues->sum(fn ($e) => $e->prerequis->count());

                $lignes[] = [
                    'code' => $semestre->code,
                    'ues' => $ues->count(),
                    'ecues' => $ecues->count(),
                    'credits' => $credits,
                    'cm' => $cm, 'td' => $td, 'tp' => $tp,
                    'heures' => $cm + $td + $tp,
                ];

                foreach (['credits' => $credits, 'cm' => $cm, 'td' => $td, 'tp' => $tp] as $cle => $valeur) {
                    $sousTotaux[$cle] += $valeur;
                }
                $sousTotaux['ues'] += $ues->count();
                $sousTotaux['ecues'] += $ecues->count();
            }

            $niveaux[] = ['code' => $niveau->code, 'semestres' => $lignes, 'totaux' => $sousTotaux];

            foreach ($sousTotaux as $cle => $valeur) {
                $totaux[$cle] += $valeur;
            }
        }

        $creditsSemestres = collect($niveaux)->flatMap(fn ($n) => $n['semestres'])->pluck('credits')->all();

        return [
            'programme' => $programme->code,
            'programme_nom' => $programme->nom,
            'niveaux' => $niveaux,
            'totaux' => $totaux + ['heures' => $totaux['cm'] + $totaux['td'] + $totaux['tp']],
            'types_ue' => $typesUe,
            'liens_prerequis' => $liensPrerequis,
            'ecarts' => [
                'credits_min' => $creditsSemestres === [] ? null : min($creditsSemestres),
                'credits_max' => $creditsSemestres === [] ? null : max($creditsSemestres),
            ],
            'sources' => 'unites_enseignement.credits · matieres.volume_cm/td/tp · unites_enseignement.type · ecue_prerequis',
            'formules' => 'crédits semestre = Σ crédits UE du semestre ; heures = Σ volumes ECUE rattachées ; '
                .'les ECUE standalone (ue_id NULL) sont exclues (repli legacy, pas de rattachement deviné).',
        ];
    }
}
