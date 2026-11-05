<?php

namespace App\Services\Acquis;

use App\Models\Etudiant;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\UniteEnseignement as Ue;

/**
 * GESTION DES TRANSITIONS DE MAQUETTES PÉDAGOGIQUES ET DES VERSIONS DE PROGRAMMES (LOOP 28 & LOOP 36).
 *
 * Analyse les ruptures de maquettes (scissions, fusions, suppressions, renommages)
 * et sépare rigoureusement :
 * 1. La conservation de l'historique (notes et sessions passées).
 * 2. La conservation intangible des crédits acquis à vie (>= 10/20).
 * 3. La reconnaissance dans la nouvelle maquette.
 * 4. L'équivalence officielle (validée par commission/arrêté vs simple proposition heuristique).
 *
 * RÈGLE D'OR : Aucune équivalence officielle ne doit être créée uniquement sur la base du code ou du nom de l'UE.
 */
class TransitionMaquettePedagogiqueService
{
    /**
     * Analyse la transition pédagogique entre un programme d'origine et un programme cible.
     *
     * @param  array<string, array<string, mixed>>  $matriceReglementaireOfficielle
     * @return array<string, mixed>
     */
    public function analyserTransitionProgramme(
        Programme $origine,
        Programme $cible,
        array $matriceReglementaireOfficielle = []
    ): array {
        $uesOrigine = Ue::where('programme_id', $origine->id)->with('ecues')->get();
        $uesCible = Ue::where('programme_id', $cible->id)->with('ecues')->get();

        $matrice = [];
        $nbIdentiques = 0;
        $nbRemplacees = 0;
        $nbScindees = 0;
        $nbFusionnees = 0;
        $nbSupprimees = 0;
        $nbEquivalencesEnAttente = 0;

        foreach ($uesOrigine as $ueOrig) {
            $regleOfficielle = $matriceReglementaireOfficielle[$ueOrig->code] ?? null;

            // 1. Si une règle réglementaire officielle validée est fournie (Arrêté / Commission)
            if ($regleOfficielle !== null) {
                $type = mb_strtoupper($regleOfficielle['type'] ?? 'REMPLACEMENT');
                $codeCible = $regleOfficielle['nouveau_code'] ?? null;
                $ueCible = $codeCible ? $uesCible->firstWhere('code', $codeCible) : null;

                if ($type === 'SCISSION') {
                    $nbScindees++;
                    $decision = sprintf(
                        'UE scindée en plusieurs unités (%s). Application des crédits répartis selon décision de commission.',
                        implode(', ', (array) ($regleOfficielle['codes_cibles'] ?? [$codeCible]))
                    );
                } elseif ($type === 'FUSION') {
                    $nbFusionnees++;
                    $decision = "UE fusionnée dans l'unité {$codeCible}. Crédits cumulés selon arrêté.";
                } elseif ($type === 'SUPPRIMEE') {
                    $nbSupprimees++;
                    $decision = 'UE officiellement supprimée de la maquette. Maintien des crédits en crédits libres hors maquette.';
                } else {
                    $nbRemplacees++;
                    $decision = "Remplacement officiel par l'UE {$codeCible} validé par décision réglementaire.";
                }

                $matrice[] = [
                    'ue_origine_id' => $ueOrig->id,
                    'ue_origine_code' => $ueOrig->code,
                    'ue_origine_nom' => $ueOrig->nom,
                    'ue_origine_credits' => $ueOrig->credits,
                    'relation' => $type,
                    'ue_cible' => $ueCible ? [
                        'id' => $ueCible->id,
                        'code' => $ueCible->code,
                        'nom' => $ueCible->nom,
                        'credits' => $ueCible->credits,
                    ] : null,
                    'historique_conserve' => true,
                    'credits_capitalises_conserves' => true,
                    'statut_reconnaissance' => $ueCible ? 'RECONNU_PAR_EQUIVALENCE' : 'HORS_MAQUETTE_CREDITS_LIBRES',
                    'equivalence_statut' => 'OFFICIELLEMENT_VALIDEE',
                    'decision_reglementaire' => $decision,
                ];

                continue;
            }

            // 2. Recherche de stricte identité (même code et même programme / continuité de maquette)
            $ueCorrespondante = $uesCible->firstWhere('code', $ueOrig->code);

            if ($ueCorrespondante) {
                if ((float) $ueCorrespondante->credits === (float) $ueOrig->credits) {
                    $relation = 'IDENTIQUE';
                    $decision = 'Continuité automatique : même code et même volume de crédits ECTS.';
                    $nbIdentiques++;
                    $statutReconnaissance = 'RECONNAISSANCE_DIRECTE_IDENTIQUE';
                    $equivalenceStatut = 'CONTINUITE_DE_DROIT';
                } else {
                    $relation = 'MODIFICATION_CREDITS';
                    $decision = "Même code mais volume modifié ({$ueOrig->credits} -> {$ueCorrespondante->credits} ECTS). Maintien des crédits historiquement acquis.";
                    $nbRemplacees++;
                    $statutReconnaissance = 'AJUSTEMENT_VOLUME_CREDITS';
                    $equivalenceStatut = 'PROPOSITION_A_CONFIRMER';
                }

                $matrice[] = [
                    'ue_origine_id' => $ueOrig->id,
                    'ue_origine_code' => $ueOrig->code,
                    'ue_origine_nom' => $ueOrig->nom,
                    'ue_origine_credits' => $ueOrig->credits,
                    'relation' => $relation,
                    'ue_cible' => [
                        'id' => $ueCorrespondante->id,
                        'code' => $ueCorrespondante->code,
                        'nom' => $ueCorrespondante->nom,
                        'credits' => $ueCorrespondante->credits,
                    ],
                    'historique_conserve' => true,
                    'credits_capitalises_conserves' => true,
                    'statut_reconnaissance' => $statutReconnaissance,
                    'equivalence_statut' => $equivalenceStatut,
                    'decision_reglementaire' => $decision,
                ];

                continue;
            }

            // 3. Cas sans règle officielle : détection heuristique par nom (NE JAMAIS VALIDER COMME ÉQUIVALENCE OFFICIELLE)
            $ueSimilaire = $uesCible->first(function ($u) use ($ueOrig) {
                return mb_strtolower($u->nom) === mb_strtolower($ueOrig->nom)
                    || (similar_text(mb_strtolower($u->nom), mb_strtolower($ueOrig->nom), $pct) && $pct > 80);
            });

            if ($ueSimilaire) {
                $relation = 'REMPLACEMENT';
                $decision = "Matière de dénomination similaire ({$ueOrig->code} -> {$ueSimilaire->code}). Proposition d'équivalence à soumettre obligatoirement à la commission pédagogique.";
                $nbRemplacees++;
                $nbEquivalencesEnAttente++;
                $statutReconnaissance = 'PROPOSITION_EQUIVALENCE_NON_VALIDEE';
                $equivalenceStatut = 'PROPOSITION_A_CONFIRMER';
                $cibleInfo = [
                    'id' => $ueSimilaire->id,
                    'code' => $ueSimilaire->code,
                    'nom' => $ueSimilaire->nom,
                    'credits' => $ueSimilaire->credits,
                ];
            } else {
                $relation = 'SUPPRIMEE';
                $decision = 'UE absente de la nouvelle maquette et sans correspondance directe. Conservation intangible des crédits capitalisés en crédits libres hors maquette.';
                $nbSupprimees++;
                $statutReconnaissance = 'HORS_MAQUETTE_CREDITS_LIBRES';
                $equivalenceStatut = 'SANS_EQUIVALENCE_REQUISE';
                $cibleInfo = null;
            }

            $matrice[] = [
                'ue_origine_id' => $ueOrig->id,
                'ue_origine_code' => $ueOrig->code,
                'ue_origine_nom' => $ueOrig->nom,
                'ue_origine_credits' => $ueOrig->credits,
                'relation' => $relation,
                'ue_cible' => $cibleInfo,
                'historique_conserve' => true,
                'credits_capitalises_conserves' => true,
                'statut_reconnaissance' => $statutReconnaissance,
                'equivalence_statut' => $equivalenceStatut,
                'decision_reglementaire' => $decision,
            ];
        }

        return [
            'programme_origine' => ['id' => $origine->id, 'nom' => $origine->nom, 'code' => $origine->code],
            'programme_cible' => ['id' => $cible->id, 'nom' => $cible->nom, 'code' => $cible->code],
            'matrice_transition' => $matrice,
            'ues_identiques' => $nbIdentiques,
            'ues_remplacees' => $nbRemplacees,
            'ues_scindees' => $nbScindees,
            'ues_fusionnees' => $nbFusionnees,
            'ues_supprimees' => $nbSupprimees,
            'equivalences_en_attente_validation' => $nbEquivalencesEnAttente,
            'garantie_conservation_acquis' => true,
        ];
    }

    /**
     * Établit le tableau d'équivalences officiel entre deux maquettes pédagogiques.
     *
     * @param  array<string, array<string, mixed>>  $matriceManuelle
     * @return array<string, mixed>
     */
    public function etablirTableauEquivalences(int $origineId, int $cibleId, array $matriceManuelle = []): array
    {
        $origine = Programme::findOrFail($origineId);
        $cible = Programme::findOrFail($cibleId);
        $analyse = $this->analyserTransitionProgramme($origine, $cible, $matriceManuelle);

        $correspondances = [];

        foreach ($analyse['matrice_transition'] as $item) {
            $correspondances[] = [
                'type' => $item['relation'],
                'ue_origine' => $item['ue_origine_code'],
                'ue_cible' => $item['ue_cible']['code'] ?? null,
                'credits_reconnus' => $item['ue_cible']['credits'] ?? $item['ue_origine_credits'],
                'historique_conserve' => $item['historique_conserve'],
                'credits_capitalises_conserves' => $item['credits_capitalises_conserves'],
                'statut_reconnaissance' => $item['statut_reconnaissance'],
                'equivalence_statut' => $item['equivalence_statut'],
                'decision' => $item['decision_reglementaire'],
            ];
        }

        return [
            'programme_origine' => $origine->nom,
            'programme_cible' => $cible->nom,
            'correspondances' => $correspondances,
            'statistiques' => [
                'identiques' => $analyse['ues_identiques'],
                'remplacees' => $analyse['ues_remplacees'],
                'scindees' => $analyse['ues_scindees'],
                'fusionnees' => $analyse['ues_fusionnees'],
                'supprimees' => $analyse['ues_supprimees'],
                'en_attente_commission' => $analyse['equivalences_en_attente_validation'],
            ],
            'garanties' => [
                'conservation_historique' => true,
                'conservation_credits_a_vie' => true,
            ],
        ];
    }

    /**
     * Applique les règles de transition à un étudiant redoublant qui change de version de maquette.
     *
     * @return array<string, mixed>
     */
    public function adapterAcquisNouvelleMaquette(Etudiant $etudiant, Programme $nouveauProgramme): array
    {
        $derniereInsc = $etudiant->inscriptionsAcademiques->sortByDesc('id')->first();
        $ancienProgramme = $derniereInsc?->programmeRef ?? $etudiant->programmeRef;

        if (! $ancienProgramme) {
            return [
                'succes' => false,
                'message' => 'Impossible de retrouver le programme d\'origine de l\'étudiant.',
            ];
        }

        $transition = $this->analyserTransitionProgramme($ancienProgramme, $nouveauProgramme);

        return [
            'succes' => true,
            'etudiant_id' => $etudiant->id,
            'ancien_programme' => $ancienProgramme->nom,
            'nouveau_programme' => $nouveauProgramme->nom,
            'matrice' => $transition['matrice_transition'],
            'credits_conserves' => true,
        ];
    }
}
