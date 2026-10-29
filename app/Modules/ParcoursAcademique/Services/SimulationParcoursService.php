<?php

namespace App\Modules\ParcoursAcademique\Services;

use App\Contracts\SimulationParcoursInterface;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\Note;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Services\CalculEvaluationService;
use App\Modules\Evaluation\Services\RegleEvaluationService;
use App\Modules\ParcoursAcademique\Models\ParcoursSimulation;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Services\AcademicCalculationService;
use App\Services\AcademicProgressionService;
use App\Services\AuditService;

/**
 * Simulation de progression (§2-5, §15, §17).
 * Multi-scénarios, indépendants, réversibles. N'écrit JAMAIS
 * dans notes, inscriptions, niveaux ou délibérations : seule
 * une ligne append-only parcours_simulations est créée.
 * Chaque résultat liste règles, crédits, notes, décisions et
 * conditions l'ayant produit (explicabilité).
 */
class SimulationParcoursService implements SimulationParcoursInterface
{
    public function __construct(
        private readonly AcademicCalculationService $coeur,
        private readonly CalculEvaluationService $evaluation,
        private readonly RegleEvaluationService $reglesEval,
        private readonly AcademicProgressionService $progressions,
        private readonly CreditParcoursService $credits,
        private readonly DetteAcademiqueService $dettes,
        private readonly ParcoursRegleService $regles,
        private readonly AuditService $audit,
    ) {}

    public function simuler(
        Etudiant $etudiant,
        string $scenario,
        array $hypotheses = [],
        ?InscriptionAcademique $source = null,
        ?int $auteurId = null,
    ): ParcoursSimulation {
        abort_unless(in_array($scenario, ParcoursSimulation::SCENARIOS, true), 422, 'Scénario inconnu.');

        $source ??= InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->with(['programmeRef', 'niveauRef', 'anneeUniversitaire', 'semestres'])
            ->orderByDesc('annee_universitaire_id')->first();
        abort_if($source === null || (int) $source->etudiant_id !== (int) $etudiant->id, 404);

        $resultat = match ($scenario) {
            'passage', 'redoublement', 'conditionnel', 'conservation_dettes' => $this->simulerDevenir($etudiant, $source, $scenario),
            'validation_semestre' => $this->simulerSemestre($etudiant, $source, $hypotheses),
            'compensation' => $this->simulerCompensation($etudiant, $source, $hypotheses),
            'validation_matiere' => $this->simulerMatiere($etudiant, $source, $hypotheses),
            'equivalence', 'dispense' => $this->simulerAmenagement($etudiant, $source, $scenario, $hypotheses),
            'reorientation', 'changement_parcours', 'admission_formation' => $this->simulerOrientation($etudiant, $source, $scenario, $hypotheses),
            'passerelle_master' => $this->simulerPasserelle($etudiant, $source, $hypotheses),
            default => $this->simulerDevenir($etudiant, $source, $scenario),
        };

        $resultat['avertissement'] = 'Simulation sans effet officiel : notes, crédits, niveau et décisions inchangés. Seule la délibération rend une décision officielle.';

        $simulation = ParcoursSimulation::create([
            'etudiant_id' => $etudiant->id,
            'inscription_source_id' => $source->id,
            'annee_universitaire_id' => $source->annee_universitaire_id,
            'scenario' => $scenario,
            'hypotheses' => $hypotheses,
            'resultat' => $resultat,
            'regle_reference' => implode(', ', array_slice($resultat['regles'] ?? [], 0, 3)),
            'auteur_id' => $auteurId,
        ]);

        $this->audit->tracer($etudiant, 'parcours.simulation', 'parcours_simulation', $simulation->id, null, [
            'scenario' => $scenario,
            'resultat_final' => $resultat['resultat_final'] ?? null,
        ]);

        return $simulation;
    }

    /**
     * @return array<string, mixed>
     */
    private function baseOfficielle(Etudiant $etudiant, InscriptionAcademique $source): array
    {
        $decision = DecisionDeliberation::where('etudiant_id', $etudiant->id)
            ->where('inscription_academique_id', $source->id)
            ->where('publie', true)->latest('id')->first();

        $credits = $this->credits->creditsEtudiant($etudiant);
        $dettes = $this->dettes->lister($etudiant);

        return [
            'decision_officielle' => $decision?->decision,
            'credits_obtenus' => $credits['obtenus'],
            'credits_manquants' => $credits['restants'],
            'matieres_restantes' => collect($dettes)->where('repassable', true)->pluck('matiere')->filter()->values()->all(),
            'dettes_nombre' => count($dettes),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function simulerDevenir(Etudiant $etudiant, InscriptionAcademique $source, string $scenario): array
    {
        $base = $this->baseOfficielle($etudiant, $source);
        $evaluation = $this->progressions->evaluer($etudiant, $source);
        $codeNiveau = strtoupper((string) ($source->niveauRef?->code ?? $source->niveau ?? ''));

        $regleCode = match (true) {
            $codeNiveau === 'L3' => 'ADMISSION_MASTER',
            str_starts_with($codeNiveau, 'M') => 'PASSAGE_M1_M2',
            default => $source->niveauRef?->code === 'L2' ? 'PASSAGE_L2_L3' : 'PASSAGE_L1_L2',
        };
        $regle = $this->regles->resoudre($regleCode, $source->annee_universitaire_id, $source->programme_id);

        $conditionsNecessaires = match ($scenario) {
            'redoublement' => ['Décision jury ajourne/rattrapage/redouble', 'Réinscription même niveau'],
            'conditionnel' => ['Décision admis_conditionnel publiée', 'Dettes listées à revalider'],
            'conservation_dettes' => ['Dettes identifiées et reprises', 'Aucune validation implicite'],
            default => ['Décision jury admis* publiée', 'Crédits et prérequis du niveau cible'],
        };

        $conditionsSatisfaites = [];
        $conditionsNonSatisfaites = [];

        if ($evaluation['decision'] !== null) {
            $conditionsSatisfaites[] = 'Décision officielle : '.$evaluation['decision'];
        } else {
            $conditionsNonSatisfaites[] = 'Aucune décision de jury publiée';
        }

        if ($codeNiveau === 'L3' && $scenario === 'passage') {
            $conditionsNonSatisfaites[] = 'L3 → M1 jamais automatique : admission Master requise (scénario passerelle_master)';
            $resultatFinal = 'non_progression_sans_admission';
            $justification = 'Fin de cycle Licence : AcademicProgressionService exige des cibles explicites + décision admis*. La simulation ne crée rien.';
        } else {
            $resultatFinal = $evaluation['peut_progresser'] ? $evaluation['action'] : ($evaluation['action'] === 'fin_cycle' ? 'fin_cycle' : 'blocage');
            $justification = 'Progression calculée depuis la décision officielle et l\'ordre du référentiel (AcademicProgressionService::evaluer). Hypothèse « '.$scenario.' » sans écriture.';
        }

        return array_merge($base, [
            'scenario_propose' => $scenario,
            'conditions_necessaires' => $conditionsNecessaires,
            'conditions_satisfaites' => $conditionsSatisfaites,
            'conditions_non_satisfaites' => array_merge($conditionsNonSatisfaites, $evaluation['blocages']),
            'resultat_final' => $resultatFinal,
            'justification' => $justification,
            'regles' => ['AcademicProgressionService::evaluer', $regle['reference']],
        ]);
    }

    /**
     * @param  array<string, mixed>  $hypotheses
     * @return array<string, mixed>
     */
    private function simulerSemestre(Etudiant $etudiant, InscriptionAcademique $source, array $hypotheses): array
    {
        $base = $this->baseOfficielle($etudiant, $source);
        $numero = (int) ($hypotheses['semestre'] ?? 1);
        $semestre = $source->semestres()->where('numero', $numero)->first();

        if ($semestre === null) {
            return array_merge($base, [
                'scenario_propose' => 'validation_semestre',
                'conditions_necessaires' => ['Semestre existant'],
                'conditions_satisfaites' => [],
                'conditions_non_satisfaites' => ["Semestre {$numero} introuvable pour cette inscription"],
                'resultat_final' => 'impossible',
                'justification' => 'Aucun semestre à valider : simulation sans objet.',
                'regles' => ['AcademicCalculationService::decisionSemestre'],
            ]);
        }

        $notes = Note::where('semestre_id', $semestre->id)->with('matiere')->get();
        $validees = $this->coeur->notesValideesParMatiere($notes);
        $moyenne = $this->coeur->moyennePonderee($validees);
        $obtenus = $this->coeur->creditsObtenus($validees);
        $total = $validees->sum('credits');

        $valideesCount = $validees->filter(fn ($n) => (float) $n['note'] >= 10.0)->count();

        return array_merge($base, [
            'scenario_propose' => 'validation_semestre',
            'moyenne' => $moyenne,
            'credits_obtenus_semestre' => $obtenus,
            'credits_total_semestre' => $total,
            'ecue_validees' => $valideesCount,
            'ecue_total' => $validees->count(),
            'compensation_eventuelle' => $moyenne !== null && $moyenne >= 10.0 && $obtenus < $total
                ? 'Compensation envisageable selon evaluation_regles (voir scénario compensation)'
                : 'Aucune compensation requise ou applicable',
            'decision_possible' => $this->coeur->decisionSemestre($moyenne),
            'conditions_necessaires' => ['Notes officielles du semestre', 'Seuils evaluation_regles'],
            'conditions_satisfaites' => $moyenne !== null ? ["Moyenne calculée : {$moyenne}"] : [],
            'conditions_non_satisfaites' => $moyenne === null ? ['Aucune note exploitable'] : [],
            'resultat_final' => $this->coeur->decisionSemestre($moyenne) ?? 'incalculable',
            'justification' => 'Calculé depuis les notes officielles via AcademicCalculationService (pondérée coefficients), sans écriture.',
            'regles' => ['AcademicCalculationService::decisionSemestre', 'RegleEvaluation::resolve'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $hypotheses
     * @return array<string, mixed>
     */
    private function simulerCompensation(Etudiant $etudiant, InscriptionAcademique $source, array $hypotheses): array
    {
        $base = $this->baseOfficielle($etudiant, $source);
        $regleEval = $this->reglesEval->resolve((int) $source->annee_universitaire_id, $source->programme_id);
        $synthese = $this->evaluation->syntheseEtudiant($etudiant, (int) $source->annee_universitaire_id, $source->programme_id);

        $avant = $synthese['moyenne'];
        $autorisee = (bool) ($regleEval['compensation_semestre'] ?? true);
        $apres = $synthese['decision_proposee'];

        return array_merge($base, [
            'scenario_propose' => 'compensation',
            'moyenne_avant' => $avant,
            'elements_concernes' => $synthese['detail_ues'],
            'regle_appliquee' => 'evaluation_regles compensation_semestre='.($autorisee ? 'oui' : 'non').' (seuil '.$regleEval['seuil_validation'].')',
            'resultat_apres' => $apres,
            'credits_obtenus' => $synthese['credits_obtenus'],
            'decision_possible' => $apres,
            'conditions_necessaires' => ['Moyenne ≥ seuil', 'Règle compensation autorisée'],
            'conditions_satisfaites' => array_filter([
                $avant !== null && $avant >= $regleEval['seuil_validation'] ? "Moyenne {$avant} ≥ seuil" : null,
                $autorisee ? 'Compensation autorisée' : null,
            ]),
            'conditions_non_satisfaites' => array_filter([
                $autorisee ? null : 'Compensation désactivée par le règlement',
                $avant !== null && $avant < $regleEval['seuil_validation'] ? "Moyenne {$avant} sous le seuil" : null,
            ]),
            'resultat_final' => $apres,
            'justification' => 'Simulation via CalculEvaluationService (même moteur que la délibération). Reste une hypothèse tant que le jury ne décide pas.',
            'regles' => ['CalculEvaluationService::syntheseEtudiant', 'RegleEvaluation v'.($regleEval['version'] ?? 'socle')],
        ]);
    }

    /**
     * @param  array<string, mixed>  $hypotheses
     * @return array<string, mixed>
     */
    private function simulerMatiere(Etudiant $etudiant, InscriptionAcademique $source, array $hypotheses): array
    {
        $base = $this->baseOfficielle($etudiant, $source);
        $matiereId = (int) ($hypotheses['matiere_id'] ?? 0);
        $noteFictive = isset($hypotheses['note']) ? (float) $hypotheses['note'] : null;

        if ($matiereId <= 0 || $noteFictive === null || $noteFictive < 0 || $noteFictive > 20) {
            return array_merge($base, [
                'scenario_propose' => 'validation_matiere',
                'conditions_necessaires' => ['matiere_id valide', 'note fictive 0-20'],
                'conditions_satisfaites' => [],
                'conditions_non_satisfaites' => ['Hypothèse incomplète (matiere_id + note 0-20 exigés)'],
                'resultat_final' => 'impossible',
                'justification' => 'Hypothèse « et si » inexploitable : rien n\'est modifié.',
                'regles' => ['AcademicCalculationService::moyennePonderee'],
            ]);
        }

        $notes = Note::where('inscription_academique_id', $source->id)->with('matiere')->get();
        $validees = $this->coeur->notesValideesParMatiere($notes)->toArray();
        $trouvee = false;
        foreach ($validees as &$ligne) {
            if (self::matiereIdDeLigne($ligne) === $matiereId) {
                $ligne['note'] = $noteFictive;
                $trouvee = true;
            }
        }
        unset($ligne);

        $moyenne = $this->coeur->moyennePonderee($validees);

        return array_merge($base, [
            'scenario_propose' => 'validation_matiere',
            'moyenne_simulee' => $moyenne,
            'conditions_necessaires' => ['Note fictive 0-20'],
            'conditions_satisfaites' => $trouvee ? ["Hypothèse : matière {$matiereId} = {$noteFictive}"] : [],
            'conditions_non_satisfaites' => $trouvee ? [] : ['Matière absente des notes officielles'],
            'resultat_final' => $moyenne !== null && $moyenne >= 10.0 ? 'amélioration' : 'insuffisant',
            'justification' => 'Recomposition en mémoire (notes officielles + hypothèse), moyenne pondérée recalculée, zéro écriture.',
            'regles' => ['AcademicCalculationService::moyennePonderee'],
        ]);
    }

    /**
     * Identifiant matière d'une ligne issue de `notesValideesParMatiere()`
     * convertie en tableau. Le repli relationnel doit rester compatible
     * tableau (R-04/4) : `matiere` est un tableau après `toArray()`.
     *
     * @param  array<string, mixed>  $ligne
     */
    private static function matiereIdDeLigne(array $ligne): int
    {
        return (int) ($ligne['matiere_id'] ?? data_get($ligne, 'matiere.id') ?? 0);
    }

    /**
     * @param  array<string, mixed>  $hypotheses
     * @return array<string, mixed>
     */
    private function simulerAmenagement(Etudiant $etudiant, InscriptionAcademique $source, string $scenario, array $hypotheses): array
    {
        $base = $this->baseOfficielle($etudiant, $source);
        $cible = (string) ($hypotheses['cible'] ?? ($hypotheses['cible_libelle'] ?? 'élément ciblé'));

        return array_merge($base, [
            'scenario_propose' => $scenario,
            'conditions_necessaires' => ['Demande motivée', 'Décision de la commission'],
            'conditions_satisfaites' => ["Hypothèse : {$scenario} sur « {$cible} »"],
            'conditions_non_satisfaites' => ['Décision officielle non enregistrée (simulation seule)'],
            'resultat_final' => 'dette_couverte_en_simulation',
            'justification' => 'Une '.$scenario.' validée couvrirait la dette en simulation ; l\'historique original des notes reste intact et la dette d\'origine conservée.',
            'regles' => ['ParcoursEquivalence/Dispense : couverture sans écrasement'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $hypotheses
     * @return array<string, mixed>
     */
    private function simulerOrientation(Etudiant $etudiant, InscriptionAcademique $source, string $scenario, array $hypotheses): array
    {
        $base = $this->baseOfficielle($etudiant, $source);
        $credits = $this->credits->creditsEtudiant($etudiant);
        $transferables = $credits['obtenus'];

        return array_merge($base, [
            'scenario_propose' => $scenario,
            'credits_transferables' => $transferables,
            'conditions_necessaires' => ['Dossier de demande', 'Avis commission', 'Places/capacité', 'Décision admise'],
            'conditions_satisfaites' => [
                'Historique académique conservé (inscriptions d\'origine intactes)',
                "Crédits déjà obtenus transférables à l'étude : {$transferables} (équivalences à instruire)",
            ],
            'conditions_non_satisfaites' => ['Décision d\'orientation non enregistrée (simulation seule)'],
            'resultat_final' => 'orientation_envisageable',
            'justification' => 'Simulation de réorientation/changement : ancien et nouveau parcours tracés, historique précédent accessible, aucune inscription créée.',
            'regles' => ['AcademicProgressionService : cibles explicites exigent admis*'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $hypotheses
     * @return array<string, mixed>
     */
    private function simulerPasserelle(Etudiant $etudiant, InscriptionAcademique $source, array $hypotheses): array
    {
        $base = $this->baseOfficielle($etudiant, $source);
        $regle = $this->regles->resoudre('ADMISSION_MASTER', $source->annee_universitaire_id, $source->programme_id);
        $criteres = $regle['criteres'];

        $satisfaites = [];
        $nonSatisfaites = [];

        $base['credits_obtenus'] >= ($criteres['credits_requis'] ?? 180)
            ? $satisfaites[] = 'Crédits suffisants ('.$base['credits_obtenus'].')'
            : $nonSatisfaites[] = 'Crédits insuffisants ('.$base['credits_obtenus'].'/'.($criteres['credits_requis'] ?? 180).')';

        if (in_array($base['decision_officielle'], ['admis', 'admis_compensation', 'admis_conditionnel'], true)) {
            $satisfaites[] = 'Décision '.$base['decision_officielle'];
        } else {
            $nonSatisfaites[] = 'Décision admis* requise (actuelle : '.($base['decision_officielle'] ?? 'aucune').')';
        }

        if (isset($hypotheses['programme_cible_id'])) {
            $programmeCible = Programme::find((int) $hypotheses['programme_cible_id']);
            if ($programmeCible === null || $programmeCible->actif === false) {
                $nonSatisfaites[] = 'Programme cible introuvable ou inactif.';
            } else {
                $satisfaites[] = 'Programme cible : '.$programmeCible->nom;
            }

            if (isset($hypotheses['niveau_cible_id'])) {
                $niveauCible = Niveau::find((int) $hypotheses['niveau_cible_id']);
                if ($niveauCible === null || (int) $niveauCible->programme_id !== (int) $hypotheses['programme_cible_id']) {
                    $nonSatisfaites[] = 'Niveau cible hors du programme cible.';
                } else {
                    $satisfaites[] = 'Niveau cible cohérent : '.$niveauCible->code;
                }
            }
        }

        $nonSatisfaites[] = 'Décision d\'admission Master (commission) : jamais automatique';

        return array_merge($base, [
            'scenario_propose' => 'passerelle_master',
            'conditions_necessaires' => ['Diplôme Licence', 'Crédits ≥ '.($criteres['credits_requis'] ?? 180), 'Résultats/prérequis', 'Décision d\'admission'],
            'conditions_satisfaites' => $satisfaites,
            'conditions_non_satisfaites' => $nonSatisfaites,
            'resultat_final' => $nonSatisfaites === [] ? 'eligible_sous_reserve_admission' : 'non_eligible',
            'justification' => 'Éligibilité analysée sur crédits, résultats et prérequis ; le passage L3 → M1 dépend de la décision d\'admission, jamais d\'un automatisme.',
            'regles' => [$regle['reference']],
        ]);
    }
}
