<?php

namespace App\Services\Acquis;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\Note;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\ParcoursAcademique\Models\ParcoursDispense;
use App\Modules\ParcoursAcademique\Models\ParcoursEquivalence;
use App\Modules\Referentiel\Models\Programme;

/**
 * MOTEUR CENTRAL DE DÉCISION ACADÉMIQUE (LOOP 16).
 *
 * Détermine le traitement académique de chaque unité d'enseignement et élément constitutif
 * en appliquant rigoureusement les Priorités 1 à 8.
 *
 * Principes stricts :
 * - Lecture seule : ZÉRO mutation pendant le calcul (pas de modification de note, PV, décision ou inscription).
 * - Déterministe, reproductible et explicable.
 * - Conforme au Décret n° 2022-631 et à l'Arrêté du 7 juillet 2023.
 */
class MoteurDecisionAcademiqueService
{
    public function __construct(
        private readonly ReglementationAcquisService $reglementationService,
        private readonly CalculAcquisPedagogiquesService $calculAcquisService,
    ) {}

    /**
     * Détermine la décision académique pour un étudiant donné.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function determinerDecision(
        Etudiant $etudiant,
        int|InscriptionAcademique|null $anneeUniversitaireId = null,
        ?int $formationId = null,
        ?int $parcoursId = null,
        ?string $versionProgramme = null,
        array $options = []
    ): array {
        $etudiant->loadMissing(['programmeRef.typeDiplome', 'faculte']);

        // =========================================================================
        // PRIORITÉ 1 — VALIDITÉ DES SOURCES (Notes définitives et délibérations)
        // =========================================================================
        if ($anneeUniversitaireId instanceof InscriptionAcademique) {
            $inscription = $anneeUniversitaireId;
            $anneeUniversitaireId = $inscription->annee_universitaire_id;
        } else {
            $inscription = InscriptionAcademique::where('etudiant_id', $etudiant->id)
                ->when($anneeUniversitaireId, fn ($q) => $q->where('annee_universitaire_id', $anneeUniversitaireId))
                ->when($formationId, fn ($q) => $q->where('programme_id', $formationId))
                ->with(['programmeRef.typeDiplome', 'anneeUniversitaire', 'semestres'])
                ->latest('id')
                ->first();
        }

        $deliberationObj = null;
        $deliberationPubliee = false;
        $sourcesValides = true;
        $anomalies = [];
        $decisionsAVerifier = [];

        if ($inscription !== null) {
            $deliberationObj = DecisionDeliberation::where('etudiant_id', $etudiant->id)
                ->where('inscription_academique_id', $inscription->id)
                ->latest('id')
                ->first();

            // Si aucune délibération sur cette inscription spécifique, se référer aux délibérations antérieures publiées
            if ($deliberationObj === null) {
                $deliberationObj = DecisionDeliberation::where('etudiant_id', $etudiant->id)
                    ->where('publie', true)
                    ->latest('id')
                    ->first();
            }

            if ($deliberationObj !== null) {
                $deliberationPubliee = (bool) $deliberationObj->publie;
            }
        }

        if (! $deliberationPubliee) {
            $sourcesValides = false;
            $anomalies[] = 'Résultats provisoires : aucune délibération officielle publiée n\'est enregistrée pour ce dossier.';
        }

        // =========================================================================
        // PRIORITÉ 2 — RÉGIME APPLICABLE & VERSION DU PROGRAMME
        // =========================================================================
        $programme = $formationId !== null
            ? Programme::with('typeDiplome')->find($formationId)
            : ($inscription?->programmeRef ?? $etudiant->programmeRef);

        $regime = $this->reglementationService->resoudreRegime($programme);
        $versionProgrammeFinale = $versionProgramme ?? $programme?->version ?? 'v1.0';

        // =========================================================================
        // PRIORITÉ 3 — DÉCISIONS OFFICIELLES DU JURY
        // =========================================================================
        $decisionJury = $deliberationObj?->decision;
        $rachatJuryAccorde = false;
        if ($deliberationObj !== null && ! empty($deliberationObj->commentaire)) {
            if (stripos($deliberationObj->commentaire, 'rachat') !== false || stripos($decisionJury ?? '', 'rachat') !== false) {
                $rachatJuryAccorde = true;
            }
        }

        // =========================================================================
        // PRIORITÉ 4 — ACQUIS ANTÉRIEURS (Capitalisation à vie across enrollments)
        // =========================================================================
        $notesHistoriques = Note::where('etudiant_id', $etudiant->id)
            ->whereNotNull('note')
            ->with(['matiere.ue'])
            ->get();

        $dispensesValidees = ParcoursDispense::where('etudiant_id', $etudiant->id)
            ->where('statut', 'validee')
            ->get();

        $equivalencesValidees = ParcoursEquivalence::where('etudiant_id', $etudiant->id)
            ->where('statut', 'validee')
            ->get();

        // Récupération des UEs du parcours
        $ues = $this->calculAcquisService->recupererUesDuParcours($programme, $inscription);

        $unitesResultats = [];
        $ecuesResultats = [];
        $elementsConserves = [];
        $elementsAReprendre = [];
        $explications = [];

        $totalCreditsCapitalises = 0;
        $totalCreditsNonAcquis = 0;
        $creditsRequis = (int) ($programme?->credits_requis ?? 60);

        // =========================================================================
        // PRIORITÉS 5, 6, 7 & 8 — CAPITALISATION, COMPENSATION, SEUILS & PARCOURS
        // =========================================================================
        foreach ($ues as $ue) {
            $ueCredits = (int) $ue->credits;
            $ecuesUe = $ue->ecues;

            $dispenseUe = $dispensesValidees->first(fn ($d) => (int) $d->ue_id === (int) $ue->id);
            $equivalenceUe = $equivalencesValidees->first(fn ($e) => (int) $e->cible_ue_id === (int) $ue->id);

            // Cas Dispense ou Équivalence validée sur toute l'UE
            if ($dispenseUe !== null || $equivalenceUe !== null) {
                $motif = $dispenseUe !== null
                    ? "Dispense officielle accordée ({$dispenseUe->motif})"
                    : "Équivalence officielle validée ({$equivalenceUe->justification})";

                $unitesResultats[] = [
                    'ue_id' => $ue->id,
                    'code' => $ue->code,
                    'nom' => $ue->nom,
                    'statut' => 'CAPITALISEE',
                    'credits_capitalises' => $ueCredits,
                    'credits_potentiels' => $ueCredits,
                    'reprise_requise' => false,
                    'motif' => $motif,
                    'regle_appliquee' => 'Arrêté du 7 juillet 2023 - Art. 10 (Dispense/Équivalence)',
                    'ecues' => [],
                ];

                $totalCreditsCapitalises += $ueCredits;
                $elementsConserves[] = [
                    'type' => 'UE',
                    'id' => $ue->id,
                    'code' => $ue->code,
                    'nom' => $ue->nom,
                    'statut' => 'CAPITALISEE',
                    'credits' => $ueCredits,
                    'motif' => $motif,
                ];

                $explications[] = "UE {$ue->code} validée par acte administratif officiel ({$motif}).";

                continue;
            }

            // Évaluation des notes des ECUEs de l'UE
            $sommePoints = 0.0;
            $sommeCoeffs = 0.0;
            $ecueMoinsDeSeuil = false;
            $detailEcuesUe = [];

            foreach ($ecuesUe as $ecue) {
                $ecueId = (int) $ecue->id;
                $ecueCredits = (int) ($ecue->credits ?? 0);
                $coeff = (float) ($ecue->coefficient > 0 ? $ecue->coefficient : 1.0);

                // Dispense ou équivalence sur l'ECUE ?
                $dispenseEcue = $dispensesValidees->first(fn ($d) => (int) $d->matiere_id === $ecueId);
                $equivalenceEcue = $equivalencesValidees->first(fn ($e) => (int) $e->cible_matiere_id === $ecueId);

                // Note historique maximale de l'étudiant
                $meilleureNote = null;
                $notesEcue = $notesHistoriques->where('matiere_id', $ecueId);
                if ($notesEcue->isNotEmpty()) {
                    $meilleureNote = (float) $notesEcue->max('note');
                }

                if ($dispenseEcue !== null || $equivalenceEcue !== null) {
                    $motifEcue = $dispenseEcue !== null
                        ? 'Dispense officielle sur matière'
                        : 'Équivalence reconnue sur matière';

                    $statutEcue = 'CAPITALISE';
                    $repriseEcue = false;
                    $creditsEcueAcquis = $ecueCredits;
                } elseif ($meilleureNote !== null && $meilleureNote >= 10.0) {
                    if ($ecueCredits > 0) {
                        $statutEcue = 'CAPITALISE';
                        $motifEcue = "Note validante de {$meilleureNote}/20 avec crédits propres ({$ecueCredits} ECTS)";
                        $creditsEcueAcquis = $ecueCredits;
                    } else {
                        $statutEcue = 'SANS_CREDITS_INDEPENDANTS';
                        $motifEcue = "Note validante de {$meilleureNote}/20 mais aucun crédit propre (Art. 8)";
                        $creditsEcueAcquis = 0;
                    }
                    $repriseEcue = false;
                } else {
                    $creditsEcueAcquis = 0;
                    $seuilEliminatoire = $regime['seuil_competence_defaut'] ?? $regime['seuil_note_eliminatoire'] ?? null;
                    if ($seuilEliminatoire !== null && $meilleureNote !== null && $meilleureNote < $seuilEliminatoire) {
                        $statutEcue = 'SEUIL_NON_ATTEINT';
                        $motifEcue = "Note de {$meilleureNote}/20 strictement inférieure au seuil éliminatoire ({$seuilEliminatoire}/20, Art. 13)";
                        $ecueMoinsDeSeuil = true;
                    } elseif ($meilleureNote === null) {
                        $statutEcue = 'EN_ATTENTE_RESULTAT';
                        $motifEcue = 'Aucune note enregistrée pour cette matière';
                    } else {
                        $statutEcue = 'NON_CAPITALISE';
                        $motifEcue = "Note de {$meilleureNote}/20 insuffisante (< 10/20)";
                    }
                    $repriseEcue = true;
                }

                if ($meilleureNote !== null) {
                    $sommePoints += ($meilleureNote * $coeff);
                    $sommeCoeffs += $coeff;
                }

                $detailEcuesUe[] = [
                    'matiere_id' => $ecueId,
                    'code' => $ecue->code,
                    'nom' => $ecue->nom,
                    'credits' => $ecueCredits,
                    'coefficient' => $coeff,
                    'note_retenue' => $meilleureNote,
                    'statut' => $statutEcue,
                    'credits_acquis' => $creditsEcueAcquis,
                    'reprise_requise' => $repriseEcue,
                    'motif' => $motifEcue,
                ];

                $ecuesResultats[] = $detailEcuesUe[array_key_last($detailEcuesUe)];
            }

            $moyenneUe = $sommeCoeffs > 0 ? round($sommePoints / $sommeCoeffs, 2) : 0.0;

            // PRIORITÉ 5 — Capitalisation de l'UE
            if ($moyenneUe >= 10.0) {
                $statutUe = 'CAPITALISEE';
                $creditsUeAcquis = $ueCredits;
                $repriseUe = false;
                $regleAppliquee = 'Arrêté du 7 juillet 2023 - Art. 8 (Capitalisation UE)';
                $motifUe = "Moyenne de l'UE ({$moyenneUe}/20) >= 10/20 : capitalisation intégrale et définitive de plein droit";
                $totalCreditsCapitalises += $ueCredits;

                $elementsConserves[] = [
                    'type' => 'UE',
                    'id' => $ue->id,
                    'code' => $ue->code,
                    'nom' => $ue->nom,
                    'statut' => 'CAPITALISEE',
                    'credits' => $ueCredits,
                    'moyenne' => $moyenneUe,
                    'motif' => $motifUe,
                ];
            } else {
                // PRIORITÉ 7 — Contrôle du seuil éliminatoire
                if ($ecueMoinsDeSeuil) {
                    $statutUe = 'SEUIL_COMPETENCE_NON_ATTEINT';
                    $creditsUeAcquis = 0;
                    $repriseUe = true;
                    $regleAppliquee = 'Arrêté du 7 juillet 2023 - Art. 13 (Seuil éliminatoire)';
                    $motifUe = "Note éliminatoire présente dans l'UE : toute compensation est rigoureusement bloquée";
                    $totalCreditsNonAcquis += $ueCredits;
                } else {
                    // PRIORITÉ 6 — Compensation semestrielle ou de jury
                    $compensationAutorisee = (bool) ($regime['compensation_autorisee'] ?? true);
                    $moyenneSemestre = (float) ($deliberationObj?->moyenne ?? 0.0);

                    if ($compensationAutorisee && $deliberationPubliee && $moyenneSemestre >= 10.0) {
                        $statutUe = 'VALIDEE_PAR_COMPENSATION';
                        $creditsUeAcquis = $ueCredits;
                        $repriseUe = false;
                        $regleAppliquee = 'Arrêté du 7 juillet 2023 - Art. 9 (Compensation de jury)';
                        $motifUe = "UE validée par compensation grâce à la moyenne générale du semestre ({$moyenneSemestre}/20)";
                        $totalCreditsCapitalises += $ueCredits;

                        $elementsConserves[] = [
                            'type' => 'UE',
                            'id' => $ue->id,
                            'code' => $ue->code,
                            'nom' => $ue->nom,
                            'statut' => 'VALIDEE_PAR_COMPENSATION',
                            'credits' => $ueCredits,
                            'moyenne' => $moyenneUe,
                            'motif' => $motifUe,
                        ];
                    } else {
                        $statutUe = ! $deliberationPubliee ? 'EN_ATTENTE_DELIBERATION' : 'NON_VALIDEE';
                        $creditsUeAcquis = 0;
                        $repriseUe = true;
                        $regleAppliquee = 'Arrêté du 7 juillet 2023 - Art. 8 & 10';
                        $motifUe = ! $deliberationPubliee
                            ? 'Délibération non publiée : statut provisoire'
                            : "Moyenne d'UE ({$moyenneUe}/20) insuffisante et absence de compensation";
                        $totalCreditsNonAcquis += $ueCredits;

                        // Traitement unitaire des ECUEs à conserver vs reprendre
                        foreach ($detailEcuesUe as $ecueItem) {
                            if ($ecueItem['statut'] === 'CAPITALISE') {
                                $totalCreditsCapitalises += $ecueItem['credits_acquis'];
                                $elementsConserves[] = [
                                    'type' => 'ECUE',
                                    'ue_id' => $ue->id,
                                    'id' => $ecueItem['matiere_id'],
                                    'code' => $ecueItem['code'],
                                    'nom' => $ecueItem['nom'],
                                    'statut' => 'CAPITALISE',
                                    'credits' => $ecueItem['credits_acquis'],
                                    'motif' => $ecueItem['motif'],
                                ];
                            } else {
                                $elementsAReprendre[] = [
                                    'type' => 'ECUE',
                                    'ue_id' => $ue->id,
                                    'ue_nom' => $ue->nom,
                                    'id' => $ecueItem['matiere_id'],
                                    'code' => $ecueItem['code'],
                                    'nom' => $ecueItem['nom'],
                                    'credits' => $ecueItem['credits'],
                                    'statut' => $ecueItem['statut'],
                                    'note' => $ecueItem['note_retenue'],
                                    'motif' => $ecueItem['motif'],
                                ];
                            }
                        }
                    }
                }
            }

            $unitesResultats[] = [
                'ue_id' => $ue->id,
                'code' => $ue->code,
                'nom' => $ue->nom,
                'moyenne' => $moyenneUe,
                'statut' => $statutUe,
                'credits_capitalises' => $creditsUeAcquis,
                'credits_potentiels' => $ueCredits,
                'reprise_requise' => $repriseUe,
                'motif' => $motifUe,
                'regle_appliquee' => $regleAppliquee,
                'ecues' => $detailEcuesUe,
            ];

            $explications[] = "UE {$ue->code} ({$ue->nom}) : statut [{$statutUe}]. Motif : {$motifUe}. Règle : {$regleAppliquee}.";
        }

        $totalCreditsRestants = max(0, $creditsRequis - $totalCreditsCapitalises);
        $statutAnalyse = empty($anomalies) ? 'ANALYSE_TERMINEE' : 'ANALYSE_AVEC_ANOMALIES';

        return [
            'etudiant_id' => $etudiant->id,
            'annee_universitaire' => $inscription?->anneeUniversitaire?->libelle ?? 'N/A',
            'formation_id' => $programme?->id,
            'programme' => $programme?->nom,
            'parcours_id' => $parcoursId,
            'version_programme' => $versionProgrammeFinale,
            'statut_analyse' => $statutAnalyse,
            'sources_valides' => $sourcesValides,
            'regime' => $regime,
            'unites' => $unitesResultats,
            'ecues' => $ecuesResultats,
            'credits' => [
                'capitalises' => $totalCreditsCapitalises,
                'non_acquis' => $totalCreditsNonAcquis,
                'restants' => $totalCreditsRestants,
                'total_requis' => $creditsRequis,
            ],
            'elements_conserves' => $elementsConserves,
            'elements_a_reprendre' => $elementsAReprendre,
            'decisions_a_verifier' => $decisionsAVerifier,
            'anomalies' => $anomalies,
            'references_regles' => [
                'Arrêté du 7 juillet 2023 - Art. 8 (Capitalisation des UE et ECUEs à crédits propres)',
                'Arrêté du 7 juillet 2023 - Art. 9 (Compensation semestrielle territoriale)',
                'Arrêté du 7 juillet 2023 - Art. 10 (Conservation définitive des acquis en redoublement)',
                'Arrêté du 7 juillet 2023 - Art. 13 (Seuils éliminatoires bloquant la compensation)',
                'Arrêté du 7 juillet 2023 - Art. 16 (Rachat discrétionnaire du jury)',
            ],
            'explications' => $explications,
            'decision_definitive' => $deliberationPubliee && empty($anomalies),
        ];
    }
}
