<?php

namespace App\Services\Acquis;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\ParcoursAcademique\Models\ParcoursDispense;
use App\Modules\ParcoursAcademique\Models\ParcoursEquivalence;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\UniteEnseignement;
use Illuminate\Database\Eloquent\Collection;

/**
 * Moteur de calcul, capitalisation et diagnostic des acquis pédagogiques (LOOP 02 à 05).
 * Conforme au Décret n° 2022-631 et à l'Arrêté du 7 juillet 2023.
 */
class CalculAcquisPedagogiquesService
{
    public function __construct(
        private readonly ReglementationAcquisService $reglementationService,
    ) {}

    /**
     * Analyse exhaustive des acquis académiques d'un étudiant.
     *
     * @return array<string, mixed>
     */
    public function analyserAcquis(Etudiant $etudiant, ?InscriptionAcademique $inscription = null): array
    {
        $etudiant->loadMissing(['programmeRef.typeDiplome', 'faculte']);

        $inscription ??= InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->with(['programmeRef.typeDiplome', 'anneeUniversitaire', 'semestres'])
            ->latest('id')
            ->first();

        $programme = $inscription?->programmeRef ?? $etudiant->programmeRef;

        // Résolution fail-closed du régime applicable (LOOP 01)
        $regime = $this->reglementationService->resoudreRegime($programme);

        // Décision officielle publiée du jury (LOOP 03)
        $decisionDelib = null;
        $deliberationPubliee = false;

        if ($inscription !== null) {
            $decisionObj = DecisionDeliberation::where('etudiant_id', $etudiant->id)
                ->where('inscription_academique_id', $inscription->id)
                ->latest('id')
                ->first();

            // Si aucune délibération sur cette inscription spécifique, se référer aux délibérations publiées antérieures
            if ($decisionObj === null) {
                $decisionObj = DecisionDeliberation::where('etudiant_id', $etudiant->id)
                    ->where('publie', true)
                    ->latest('id')
                    ->first();
            }

            if ($decisionObj !== null) {
                $deliberationPubliee = (bool) $decisionObj->publie;
                $decisionDelib = [
                    'id' => $decisionObj->id,
                    'decision' => $decisionObj->decision,
                    'moyenne' => $decisionObj->moyenne ? (float) $decisionObj->moyenne : null,
                    'credits_obtenus' => (int) $decisionObj->credits_obtenus,
                    'credits_total' => (int) $decisionObj->credits_total,
                    'ues_validees' => (int) $decisionObj->ues_validees,
                    'publie' => $deliberationPubliee,
                ];
            }
        }

        // Récupération des UEs du parcours
        $ues = $this->recupererUesDuParcours($programme, $inscription);

        $anomalies = [];
        $uesResultats = [];
        $elementsConserves = [];
        $elementsAReprendre = [];

        $totalCreditsCapitalisesUe = 0;
        $totalCreditsCapitalisesEcue = 0;
        $totalCreditsCompenses = 0;

        // Acquis extérieurs (dispenses et équivalences validées)
        $dispenses = ParcoursDispense::where('etudiant_id', $etudiant->id)
            ->where('statut', 'validee')
            ->get();
        $equivalences = ParcoursEquivalence::where('etudiant_id', $etudiant->id)
            ->where('statut', 'validee')
            ->get();

        foreach ($ues as $ue) {
            $detailUe = $this->analyserUe(
                $etudiant,
                $ue,
                $inscription,
                $regime,
                $deliberationPubliee,
                $decisionDelib,
                $dispenses,
                $equivalences
            );

            $uesResultats[] = $detailUe;

            if (! empty($detailUe['anomalie'])) {
                $anomalies[] = "UE {$ue->code} : {$detailUe['anomalie']}";
            }

            if ($detailUe['statut'] === 'UE_CAPITALISEE') {
                $totalCreditsCapitalisesUe += $detailUe['credits_acquis'];
                $elementsConserves[] = [
                    'type' => 'UE',
                    'id' => $ue->id,
                    'code' => $ue->code,
                    'nom' => $ue->nom,
                    'statut' => 'UE_CAPITALISEE',
                    'moyenne' => $detailUe['moyenne'],
                    'credits' => $detailUe['credits_acquis'],
                    'motif' => "UE capitalisée de plein droit (Moyenne : {$detailUe['moyenne']}/20)",
                ];
            } elseif (in_array($detailUe['statut'], ['UE_COMPENSEE', 'VALIDEE_PAR_COMPENSATION'], true)) {
                $totalCreditsCompenses += $detailUe['credits_acquis'];
                $elementsConserves[] = [
                    'type' => 'UE',
                    'id' => $ue->id,
                    'code' => $ue->code,
                    'nom' => $ue->nom,
                    'statut' => 'UE_COMPENSEE',
                    'moyenne' => $detailUe['moyenne'],
                    'credits' => $detailUe['credits_acquis'],
                    'motif' => 'Validée par compensation du jury (Art. 9)',
                ];
            } elseif ($detailUe['statut'] === 'DISPENSE_OFFICIELLE') {
                $totalCreditsCapitalisesUe += $detailUe['credits_acquis'];
                $elementsConserves[] = [
                    'type' => 'UE',
                    'id' => $ue->id,
                    'code' => $ue->code,
                    'nom' => $ue->nom,
                    'statut' => 'DISPENSE_OFFICIELLE',
                    'moyenne' => null,
                    'credits' => $detailUe['credits_acquis'],
                    'motif' => $detailUe['motif'],
                ];
            } else {
                // UE non acquise : analyse unitaire des ECUEs
                if (! empty($detailUe['ecues'])) {
                    foreach ($detailUe['ecues'] as $ecueDetail) {
                        if (in_array($ecueDetail['statut'], ['ECUE_CAPITALISE', 'DISPENSE_OFFICIELLE', 'EQUIVALENCE_VALIDEE'], true)) {
                            $totalCreditsCapitalisesEcue += $ecueDetail['credits_acquis'];
                            $elementsConserves[] = [
                                'type' => 'ECUE',
                                'ue_id' => $ue->id,
                                'id' => $ecueDetail['matiere_id'],
                                'code' => $ecueDetail['code'],
                                'nom' => $ecueDetail['nom'],
                                'statut' => $ecueDetail['statut'],
                                'note' => $ecueDetail['note_reconnue'],
                                'credits' => $ecueDetail['credits_acquis'],
                                'motif' => $ecueDetail['motif'],
                            ];
                        } else {
                            $elementsAReprendre[] = [
                                'type' => 'ECUE',
                                'ue_id' => $ue->id,
                                'ue_nom' => $ue->nom,
                                'semestre' => $ue->semestre?->numero ?? 1,
                                'id' => $ecueDetail['matiere_id'],
                                'code' => $ecueDetail['code'],
                                'nom' => $ecueDetail['nom'],
                                'credits' => $ecueDetail['credits_propres_valeur'] ?? 0,
                                'statut' => $ecueDetail['statut'],
                                'note' => $ecueDetail['note_reconnue'],
                                'motif' => $ecueDetail['motif'],
                            ];
                        }
                    }
                } else {
                    $elementsAReprendre[] = [
                        'type' => 'UE',
                        'id' => $ue->id,
                        'code' => $ue->code,
                        'nom' => $ue->nom,
                        'semestre' => $ue->semestre?->numero ?? 1,
                        'credits' => $detailUe['credits_potentiels'],
                        'statut' => $detailUe['statut'],
                        'moyenne' => $detailUe['moyenne'],
                        'motif' => $detailUe['motif'],
                    ];
                }
            }
        }

        $totalCreditsAcquis = $totalCreditsCapitalisesUe + $totalCreditsCapitalisesEcue + $totalCreditsCompenses;
        $totalCreditsProgramme = (int) ($programme->credits_requis ?? 60);
        $creditsRestants = max(0, $totalCreditsProgramme - $totalCreditsAcquis);

        return [
            'etudiant_id' => $etudiant->id,
            'etudiant' => [
                'id' => $etudiant->id,
                'nom' => $etudiant->nom,
                'prenom' => $etudiant->prenom,
                'cin' => $etudiant->cin,
                'matricule' => $etudiant->matricule ?? $etudiant->numero_etudiant,
            ],
            'inscription_source_id' => $inscription?->id,
            'programme' => $programme?->nom,
            'regime' => [
                'code' => $regime['code_regime'] ?? 'LMD_LICENCE',
                'nom' => $regime['nom'] ?? 'Régime LMD',
                'annee' => $inscription?->anneeUniversitaire?->libelle,
                'session' => 'Principale',
                'deliberation_publiee' => $deliberationPubliee,
                'statut_deliberation' => $deliberationPubliee ? 'PUBLIEE' : 'EN_ATTENTE_DELIBERATION',
                'reglementation' => $regime,
            ],
            'regime_reglementaire' => $regime['nom'],
            'decision_deliberation' => $decisionDelib,
            'deliberation_publiee' => $deliberationPubliee,
            'totaux' => [
                'credits_acquis' => $totalCreditsAcquis,
                'credits_a_obtenir' => $creditsRestants,
                'moyenne_generale' => $decisionDelib['moyenne'] ?? null,
            ],
            'credits_total_programme' => $totalCreditsProgramme,
            'credits_capitalises_ue' => $totalCreditsCapitalisesUe,
            'credits_capitalises_ecue' => $totalCreditsCapitalisesEcue,
            'credits_valides_compensation' => $totalCreditsCompenses,
            'total_credits_acquis' => $totalCreditsAcquis,
            'total_credits_restants' => $creditsRestants,
            'ues' => $uesResultats,
            'elements_a_reprendre' => $elementsAReprendre,
            'ecues_a_repasser' => $elementsAReprendre,
            'elements_conserves' => $elementsConserves,
            'anomalies' => $anomalies,
            'peut_redoubler_automatiquement' => empty($anomalies) && $deliberationPubliee,
        ];
    }

    /**
     * Analyse détaillée d'une Unité d'Enseignement (UE).
     */
    private function analyserUe(
        Etudiant $etudiant,
        UniteEnseignement $ue,
        ?InscriptionAcademique $inscription,
        array $regime,
        bool $deliberationPubliee,
        ?array $decisionDelib,
        Collection $dispenses,
        Collection $equivalences
    ): array {
        $creditsUe = (int) ($ue->credits ?? 6);
        $coefficientUe = (float) ($ue->coefficient ?? 3.0);

        // Vérifier si dispense officielle sur l'UE (matiere_id doit être vide)
        $dispenseUe = $dispenses->first(fn ($d) => (int) $d->ue_id === (int) $ue->id && empty($d->matiere_id));
        if ($dispenseUe !== null) {
            return [
                'ue_id' => $ue->id,
                'code' => $ue->code,
                'nom' => $ue->nom,
                'semestre' => $ue->semestre?->numero ?? 1,
                'credits' => $creditsUe,
                'credits_potentiels' => $creditsUe,
                'credits_acquis' => $creditsUe,
                'moyenne' => null,
                'statut' => 'DISPENSE_OFFICIELLE',
                'statut_libelle' => 'Dispensée',
                'est_capitalisee' => false,
                'est_compensee' => false,
                'est_validee' => true,
                'motif' => "Dispense officielle accordée (#{$dispenseUe->id}) par {$dispenseUe->responsable}",
                'ecues' => [],
                'anomalie' => null,
            ];
        }

        // Récupération des ECUEs (Matières) rattachés à cette UE
        $ecues = $ue->ecues()->where('actif', true)->get();
        if ($ecues->isEmpty()) {
            $ecues = Matiere::where('ue_id', $ue->id)->where('actif', true)->get();
        }

        $notesEcues = [];
        $totalPondere = 0.0;
        $totalCoeff = 0.0;
        $seuilEliminatoireTouche = false;
        $noteEliminatoireValeur = null;
        $manqueNote = false;

        $seuilCompetence = array_key_exists('seuil_competence_defaut', $regime)
            ? $regime['seuil_competence_defaut']
            : null;

        foreach ($ecues as $ecue) {
            $analyseEcue = $this->analyserEcue(
                $etudiant,
                $ecue,
                $ue,
                $inscription,
                $regime,
                $deliberationPubliee,
                $dispenses,
                $equivalences
            );

            $notesEcues[] = $analyseEcue;

            if ($analyseEcue['note_reconnue'] !== null) {
                $totalPondere += $analyseEcue['note_reconnue'] * $analyseEcue['coefficient'];
                $totalCoeff += $analyseEcue['coefficient'];

                // Vérification du seuil de compétence éliminatoire (Art. 13)
                if ($seuilCompetence !== null && $analyseEcue['note_reconnue'] < $seuilCompetence) {
                    $seuilEliminatoireTouche = true;
                    $noteEliminatoireValeur = $analyseEcue['note_reconnue'];
                }
            } else {
                $manqueNote = true;
            }
        }

        $moyenne = $totalCoeff > 0 ? round($totalPondere / $totalCoeff, 2) : null;

        // Statut par défaut
        $statut = 'NON_VALIDEE';
        $creditsAcquis = 0;
        $motif = 'Moyenne insuffisante pour capitalisation de l’UE.';
        $anomalie = null;

        if (! $deliberationPubliee) {
            $statut = 'EN_ATTENTE_DELIBERATION';
            $motif = 'Résultats provisoires : délibération officielle du jury non publiée.';

            return [
                'ue_id' => $ue->id,
                'code' => $ue->code,
                'nom' => $ue->nom,
                'semestre' => $ue->semestre?->numero ?? 1,
                'credits' => $creditsUe,
                'credits_potentiels' => $creditsUe,
                'credits_acquis' => 0,
                'moyenne' => $moyenne,
                'statut' => $statut,
                'statut_libelle' => 'En attente',
                'est_capitalisee' => false,
                'est_compensee' => false,
                'est_validee' => false,
                'motif' => $motif,
                'ecues' => $notesEcues,
                'anomalie' => null,
            ];
        }

        // Cas 1 : Capitalisation de plein droit de l'UE (Moyenne >= 10/20) - Art. 8
        if ($moyenne !== null && $moyenne >= $regime['seuil_capitalisation_ue']) {
            $statut = 'UE_CAPITALISEE';
            $creditsAcquis = $creditsUe;
            $motif = "Moyenne {$moyenne}/20 >= {$regime['seuil_capitalisation_ue']} : UE capitalisée de plein droit.";
        }
        // Cas 2 : Seuil de compétence non atteint (Art. 13) - bloque la compensation
        elseif ($seuilEliminatoireTouche) {
            $statut = 'SEUIL_COMPETENCE_NON_ATTEINT';
            $creditsAcquis = 0;
            $motif = "Note éliminatoire de {$noteEliminatoireValeur}/20 inférieure au seuil de compétence ({$seuilCompetence}/20) : compensation interdite.";
        }
        // Cas 3 : Validation par compensation officielle du jury (Art. 9)
        elseif ($decisionDelib !== null && in_array(mb_strtolower((string) $decisionDelib['decision']), ['admis_compensation', 'admis'], true)) {
            $statut = 'UE_COMPENSEE';
            $creditsAcquis = $creditsUe;
            $motif = "UE validée par compensation selon délibération officielle (#{$decisionDelib['id']}). Non transférable à un autre parcours.";
        }

        return [
            'ue_id' => $ue->id,
            'code' => $ue->code,
            'nom' => $ue->nom,
            'semestre' => $ue->semestre?->numero ?? 1,
            'credits' => $creditsUe,
            'credits_potentiels' => $creditsUe,
            'credits_acquis' => $creditsAcquis,
            'moyenne' => $moyenne,
            'statut' => $statut,
            'statut_libelle' => $statut,
            'est_capitalisee' => $statut === 'UE_CAPITALISEE',
            'est_compensee' => in_array($statut, ['UE_COMPENSEE', 'VALIDEE_PAR_COMPENSATION'], true),
            'est_validee' => in_array($statut, ['UE_CAPITALISEE', 'UE_COMPENSEE', 'VALIDEE_PAR_COMPENSATION', 'DISPENSE_OFFICIELLE'], true),
            'motif' => $motif,
            'ecues' => $notesEcues,
            'anomalie' => $anomalie,
        ];
    }

    /**
     * Analyse unitaire d'un ECUE (Matière).
     */
    private function analyserEcue(
        Etudiant $etudiant,
        Matiere $ecue,
        UniteEnseignement $ue,
        ?InscriptionAcademique $inscription,
        array $regime,
        bool $deliberationPubliee,
        Collection $dispenses,
        Collection $equivalences
    ): array {
        $coeff = (float) ($ecue->coefficient ?? 1.0);
        $creditsEcue = (int) ($ecue->credits ?? 0);
        $disposeCreditsPropres = $creditsEcue > 0;
        $estControleContinuExclusif = in_array(mb_strtolower((string) $ecue->type_evaluation), ['controle_continu', 'cc_pur', 'cc_exclusif'], true);

        // Dispense directe sur l'ECUE
        $dispenseEcue = $dispenses->firstWhere('matiere_id', $ecue->id);
        if ($dispenseEcue !== null) {
            return [
                'matiere_id' => $ecue->id,
                'code' => $ecue->code,
                'nom' => $ecue->nom,
                'coefficient' => $coeff,
                'credits_propres' => $disposeCreditsPropres,
                'credits' => $creditsEcue,
                'credits_propres_valeur' => $creditsEcue,
                'credits_acquis' => $creditsEcue,
                'note' => 10.0,
                'note_reconnue' => 10.0,
                'statut' => 'DISPENSE_OFFICIELLE',
                'statut_libelle' => 'Dispensé',
                'est_capitalise' => false,
                'est_dispense' => true,
                'est_equivalent' => false,
                'est_a_repasser' => false,
                'est_controle_continu_exclusif' => $estControleContinuExclusif,
                'motif' => "Dispense officielle (#{$dispenseEcue->id})",
            ];
        }

        // Équivalence directe sur l'ECUE
        $equivEcue = $equivalences->firstWhere('cible_matiere_id', $ecue->id);
        if ($equivEcue !== null) {
            return [
                'matiere_id' => $ecue->id,
                'code' => $ecue->code,
                'nom' => $ecue->nom,
                'coefficient' => $coeff,
                'credits_propres' => $disposeCreditsPropres,
                'credits' => $creditsEcue,
                'credits_propres_valeur' => $creditsEcue,
                'credits_acquis' => $creditsEcue,
                'note' => 10.0,
                'note_reconnue' => 10.0,
                'statut' => 'EQUIVALENCE_VALIDEE',
                'statut_libelle' => 'Équivalence',
                'est_capitalise' => false,
                'est_dispense' => false,
                'est_equivalent' => true,
                'est_a_repasser' => false,
                'est_controle_continu_exclusif' => $estControleContinuExclusif,
                'motif' => "Équivalence reconnue depuis {$equivEcue->etablissement} ({$equivEcue->origine_libelle})",
            ];
        }

        // Lecture des notes : capitalisation permanente garantie (Art. 8 & 10)
        $notes = Note::where('etudiant_id', $etudiant->id)
            ->where('matiere_id', $ecue->id)
            ->orderBy('id')
            ->get();

        $notePrincipale = $notes->first(fn ($n) => in_array(mb_strtolower((string) $n->session), ['normale', 'principale', 'session_principale'], true))?->note;
        $noteRattrapage = $notes->first(fn ($n) => in_array(mb_strtolower((string) $n->session), ['rattrapage', 'session_rattrapage'], true))?->note;

        if ($notePrincipale === null && $noteRattrapage === null && $notes->isNotEmpty()) {
            $notePrincipale = $notes->first()->note;
        }

        // Résolution de la meilleure note officielle (Art. 10 & 11)
        $noteRetenue = null;
        if ($notePrincipale !== null && $noteRattrapage !== null) {
            $noteRetenue = max((float) $notePrincipale, (float) $noteRattrapage);
        } elseif ($notePrincipale !== null) {
            $noteRetenue = (float) $notePrincipale;
        } elseif ($noteRattrapage !== null) {
            $noteRetenue = (float) $noteRattrapage;
        }

        $statut = 'NON_ACQUIS';
        $creditsAcquis = 0;
        $motif = 'Note non acquise (< 10/20).';

        if ($noteRetenue === null) {
            $statut = 'NON_EVALUE';
            $motif = 'Aucune note enregistrée.';
        } elseif (! $deliberationPubliee) {
            $statut = 'EN_ATTENTE_DELIBERATION';
            $motif = 'Résultat en attente de délibération officielle.';
        } elseif ($noteRetenue >= $regime['seuil_capitalisation_ecue']) {
            if ($disposeCreditsPropres) {
                // Article 8 : Capitalisation individuelle possible SI crédits spécifiques
                $statut = 'ECUE_CAPITALISE';
                $creditsAcquis = $creditsEcue;
                $motif = "Note {$noteRetenue}/20 >= 10 avec crédits propres ({$creditsEcue} crédits) : ECUE capitalisé et conservé.";
            } else {
                // Article 8 : Note >= 10 mais SANS crédits propres -> Pas de capitalisation autonome
                $statut = 'NOTE_SUPERIEURE_SANS_CREDIT_PROPRE';
                $creditsAcquis = 0;
                $motif = "Note {$noteRetenue}/20 >= 10 mais l’élément ne possède pas de crédits propres dans la maquette (rattaché à l’UE globale).";
            }
        }

        $estCapitalise = $statut === 'ECUE_CAPITALISE';

        return [
            'matiere_id' => $ecue->id,
            'code' => $ecue->code,
            'nom' => $ecue->nom,
            'coefficient' => $coeff,
            'credits_propres' => $disposeCreditsPropres,
            'credits' => $creditsEcue,
            'credits_propres_valeur' => $creditsEcue,
            'credits_acquis' => $creditsAcquis,
            'note' => $noteRetenue,
            'note_principale' => $notePrincipale !== null ? (float) $notePrincipale : null,
            'note_rattrapage' => $noteRattrapage !== null ? (float) $noteRattrapage : null,
            'note_reconnue' => $noteRetenue,
            'statut' => $statut,
            'statut_libelle' => $statut,
            'est_capitalise' => $estCapitalise,
            'est_dispense' => false,
            'est_equivalent' => false,
            'est_a_repasser' => ! in_array($statut, ['ECUE_CAPITALISE', 'DISPENSE_OFFICIELLE', 'EQUIVALENCE_VALIDEE'], true),
            'est_controle_continu_exclusif' => $estControleContinuExclusif,
            'motif' => $motif,
        ];
    }

    /**
     * Récupère la liste des UEs du parcours associé à l'inscription.
     *
     * @return Collection<int, UniteEnseignement>
     */
    public function recupererUesDuParcours(?Programme $programme, ?InscriptionAcademique $inscription): Collection
    {
        if ($programme === null) {
            return new Collection;
        }

        $query = UniteEnseignement::where('programme_id', $programme->id)
            ->where('actif', true);

        if ($inscription?->niveau_id) {
            $query->whereHas('semestre', fn ($q) => $q->where('niveau_id', $inscription->niveau_id));
        }

        $ues = $query->orderBy('ordre')->get();

        if ($ues->isEmpty()) {
            return UniteEnseignement::where('programme_id', $programme->id)
                ->where('actif', true)
                ->orderBy('ordre')
                ->get();
        }

        return $ues;
    }
}
