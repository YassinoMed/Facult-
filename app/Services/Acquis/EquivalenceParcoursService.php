<?php

namespace App\Services\Acquis;

use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Modules\ParcoursAcademique\Models\ParcoursEquivalence;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\UniteEnseignement;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Service de gestion des équivalences, transferts et changements de parcours (LOOP 08).
 *
 * Applique le principe strict de l'Article 9 de l'Arrêté du 7 juillet 2023 :
 * - Une UE validée par compensation reste attachée au parcours d'origine et n'est
 *   PAS transférable dans sa totalité vers un autre parcours sans décision de commission.
 * - Seules les UE et ECUE capitalisées avec crédits spécifiques peuvent faire l'objet
 *   d'une équivalence directe automatique après comparaison des programmes.
 * - Interdiction de valider une équivalence sur la seule base d'une homonymie de nom ou de code.
 */
class EquivalenceParcoursService
{
    public function __construct(
        private readonly CalculAcquisPedagogiquesService $calculAcquisService,
    ) {}

    /**
     * Analyse comparative et propositions d'équivalences entre l'ancien et le nouveau programme.
     *
     * @return array{
     *     etudiant_id: int,
     *     programme_origine: string,
     *     programme_cible: string,
     *     elements_directement_transferables: array<int, array>,
     *     elements_exigeant_commission: array<int, array>,
     *     elements_non_reconnus: array<int, array>,
     *     total_credits_transferables: int,
     *     total_credits_en_attente: int
     * }
     */
    public function analyserChangementParcours(
        Etudiant $etudiant,
        Programme $programmeCible,
        ?InscriptionAcademique $inscriptionOrigine = null
    ): array {
        $analyse = $this->calculAcquisService->analyserAcquis($etudiant, $inscriptionOrigine);

        $transferables = [];
        $exigeantCommission = [];
        $nonReconnus = [];
        $creditsTransferables = 0;
        $creditsEnAttente = 0;

        $matieresCibles = Matiere::whereHas('ue', fn ($q) => $q->where('programme_id', $programmeCible->id))
            ->get();
        $uesCibles = UniteEnseignement::where('programme_id', $programmeCible->id)->get();

        foreach ($analyse['elements_conserves'] as $element) {
            $type = $element['type'] ?? '';
            $code = $element['code'] ?? '';
            $statut = $element['statut'] ?? '';
            $credits = (int) ($element['credits'] ?? 0);

            // Article 9 : L'UE compensée ne peut être transférée d'office à un autre parcours
            if ($statut === 'VALIDEE_PAR_COMPENSATION') {
                $exigeantCommission[] = [
                    'element' => $element,
                    'motif' => 'UE validée par compensation : transfert non automatique vers un autre parcours (Art. 9). Exige examen par la commission d’équivalence.',
                    'statut_recommande' => 'SOUMIS_A_COMMISSION',
                ];
                $creditsEnAttente += $credits;

                continue;
            }

            // Recherche de correspondance dans le programme cible
            if ($type === 'UE') {
                $ueCorrespondante = $uesCibles->firstWhere('code', $code);
                if ($ueCorrespondante !== null && (int) $ueCorrespondante->credits === $credits) {
                    $transferables[] = [
                        'element_origine' => $element,
                        'element_cible' => [
                            'type' => 'UE',
                            'id' => $ueCorrespondante->id,
                            'code' => $ueCorrespondante->code,
                            'nom' => $ueCorrespondante->nom,
                            'credits' => $ueCorrespondante->credits,
                        ],
                        'statut' => 'EQUIVALENCE_DIRECTE',
                        'motif' => 'UE capitalisée avec crédits et objectifs correspondants.',
                    ];
                    $creditsTransferables += $credits;
                } else {
                    $exigeantCommission[] = [
                        'element' => $element,
                        'motif' => 'Structure d’UE ou crédits différents dans le nouveau programme : commission requise.',
                        'statut_recommande' => 'SOUMIS_A_COMMISSION',
                    ];
                    $creditsEnAttente += $credits;
                }
            } elseif ($type === 'ECUE') {
                $matiereCorrespondante = $matieresCibles->firstWhere('code', $code);
                if ($matiereCorrespondante !== null && (int) $matiereCorrespondante->credits === $credits) {
                    $transferables[] = [
                        'element_origine' => $element,
                        'element_cible' => [
                            'type' => 'ECUE',
                            'id' => $matiereCorrespondante->id,
                            'code' => $matiereCorrespondante->code,
                            'nom' => $matiereCorrespondante->nom,
                            'credits' => $matiereCorrespondante->credits,
                        ],
                        'statut' => 'EQUIVALENCE_DIRECTE',
                        'motif' => 'ECUE capitalisé individuellement avec crédits propres équivalents.',
                    ];
                    $creditsTransferables += $credits;
                } else {
                    $exigeantCommission[] = [
                        'element' => $element,
                        'motif' => 'ECUE sans équivalent strict direct : décision de commission nécessaire.',
                        'statut_recommande' => 'SOUMIS_A_COMMISSION',
                    ];
                    $creditsEnAttente += $credits;
                }
            }
        }

        return [
            'etudiant_id' => $etudiant->id,
            'programme_origine' => $analyse['programme'] ?? 'Non renseigné',
            'programme_cible' => $programmeCible->nom,
            'tous_transferables' => empty($exigeantCommission) && empty($nonReconnus),
            'elements_directement_transferables' => $transferables,
            'elements_exigeant_commission' => $exigeantCommission,
            'elements_non_transferables_automatiquement' => array_merge($exigeantCommission, $nonReconnus),
            'elements_non_reconnus' => $nonReconnus,
            'total_credits_transferables' => $creditsTransferables,
            'total_credits_en_attente' => $creditsEnAttente,
        ];
    }

    /**
     * Alias explicite pour l'analyse de transférabilité des acquis.
     */
    public function analyserTransferabiliteAcquis(
        Etudiant $etudiant,
        Programme $programmeCible,
        ?InscriptionAcademique $inscriptionOrigine = null
    ): array {
        return $this->analyserChangementParcours($etudiant, $programmeCible, $inscriptionOrigine);
    }

    /**
     * Soumet formellement une demande d'équivalence à la commission pédagogique.
     */
    public function soumettreEquivalenceCommission(
        Etudiant $etudiant,
        string $origineLibelle,
        int $origineCredits,
        ?int $cibleMatiereId,
        ?int $cibleUeId,
        string $cibleLibelle,
        ?string $etablissement = null,
        ?string $justification = null,
        ?int $auteurId = null
    ): ParcoursEquivalence {
        return DB::transaction(function () use (
            $etudiant, $origineLibelle, $origineCredits, $cibleMatiereId,
            $cibleUeId, $cibleLibelle, $etablissement, $justification, $auteurId
        ) {
            $equivalence = ParcoursEquivalence::create([
                'etudiant_id' => $etudiant->id,
                'origine_libelle' => $origineLibelle,
                'origine_credits' => $origineCredits,
                'cible_matiere_id' => $cibleMatiereId,
                'cible_ue_id' => $cibleUeId,
                'cible_libelle' => $cibleLibelle,
                'etablissement' => $etablissement,
                'statut' => 'proposee',
                'justification' => $justification,
                'auteur_id' => $auteurId,
            ]);

            HistoriqueAcademique::create([
                'etudiant_id' => $etudiant->id,
                'utilisateur_id' => $auteurId,
                'action' => 'demande_equivalence_soumise',
                'entite' => 'parcours_equivalences',
                'entite_id' => $equivalence->id,
                'ancienne_valeur' => null,
                'nouvelle_valeur' => json_encode([
                    'description' => "Demande d'équivalence soumise : {$origineLibelle} ({$origineCredits} crédits) vers {$cibleLibelle}.",
                    'etablissement' => $etablissement,
                ], JSON_UNESCAPED_UNICODE),
                'source_systeme' => 'web',
            ]);

            return $equivalence;
        });
    }

    /**
     * Enregistre la décision officielle de la commission d'équivalence.
     */
    public function statuerEquivalence(
        ParcoursEquivalence $equivalence,
        string $decision, // 'validee' ou 'refusee'
        ?string $justification = null,
        ?int $decideParId = null
    ): ParcoursEquivalence {
        if (! in_array($decision, ['validee', 'refusee'], true)) {
            throw new DomainException('Décision invalide : seules les issues « validee » ou « refusee » sont recevables.');
        }

        return DB::transaction(function () use ($equivalence, $decision, $justification, $decideParId) {
            $equivalence->update([
                'decision' => $decision,
                'statut' => $decision,
                'justification' => $justification,
                'decide_par' => $decideParId,
                'decide_le' => now(),
            ]);

            HistoriqueAcademique::create([
                'etudiant_id' => $equivalence->etudiant_id,
                'utilisateur_id' => $decideParId,
                'action' => 'decision_equivalence_enregistree',
                'entite' => 'parcours_equivalences',
                'entite_id' => $equivalence->id,
                'ancienne_valeur' => null,
                'nouvelle_valeur' => json_encode([
                    'description' => "Décision officielle de commission sur équivalence #{$equivalence->id} : {$decision}.",
                    'decision' => $decision,
                    'justification' => $justification,
                ], JSON_UNESCAPED_UNICODE),
                'source_systeme' => 'web',
            ]);

            return $equivalence;
        });
    }
}
