<?php

namespace App\Modules\Evaluation\Services;

use App\Models\IntegrationLog;
use App\Modules\Enseignement\Models\AffectationEnseignement;
use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Moteur d'affectation et d'équilibrage intelligent des surveillants (DS & Examens).
 *
 * Règles intégrées :
 * 1. Minimum 2 surveillants actifs par examen (+ 1 par salle supplémentaire si multi-salles).
 * 2. 1 enseignant de garde / réserve (rôle 'reserve', poids 0.5).
 * 3. Équilibre idéal de présence sur 3 jours par enseignant.
 * 4. Minimisation de l'écart de charge (max - min) entre enseignants.
 * 5. Respect strict de la capacité réelle des salles (candidats <= capacité utile).
 * 6. Zéro conflit horaire (épreuves concurrentes + créneaux réguliers ScheduleSlot).
 * 7. Simulation pure en mémoire avant toute écriture en base.
 * 8. Traçabilité, idempotence et application transactionnelle avec verrous pessimistes.
 */
class SurveillanceEquilibrageService
{
    // --- RATIOS ET MINIMA MÉTIER (§1) ---
    public const MIN_SURVEILLANTS_ACTIFS = 2;

    public const MIN_GARDES_RESERVE = 1;

    public const CIBLE_JOURS_PRESENCE = 3;

    /** Heuristique standard : 1 surveillant supplémentaire par tranche entamée de 50 candidats. */
    public const RATIO_ETUDIANTS_PAR_SURVEILLANT = 50;

    // --- PONDÉRATION DE CHARGE RÉELLE (§5) ---
    public const POIDS_SURVEILLANCE_ACTIVE = 1.0;

    public const POIDS_GARDE_RESERVE = 0.5;

    // --- CONSTANTES DU SCORE D'ÉQUILIBRAGE (HEURISTIQUE MULTI-CRITÈRES, §9/§12) ---
    /** Priorité globale : niveler la charge globale entre enseignants (×10). */
    public const POIDS_CHARGE_TOTALE = 10.0;

    /** Éviter la surconcentration d'examens sur un enseignant au cours d'une même journée (×15). */
    public const POIDS_CHARGE_JOUR = 15.0;

    /** Harmoniser le nombre de gardes de réserve pour ne pas toujours mobiliser les mêmes (×8). */
    public const POIDS_GARDES = 8.0;

    /** Pénalité dissuasive pour dépassement de la cible des 3 jours de présence (+35). */
    public const PENALITE_DEPASSEMENT_3_JOURS = 35.0;

    /** Pénalité d'éparpillement temporel (jours non contigus avec distance > 2 jours, ex: J1 et J5) (+10). */
    public const PENALITE_DISPERSION_TEMPORELLE = 10.0;

    /** Incitation modérée à l'ouverture d'un nouveau jour si le quota des 3 jours n'est pas encore atteint (+2). */
    public const PENALITE_NOUVEAU_JOUR = 2.0;

    // --- TAXONOMIE DES CONTRAINTES (§11) ---
    public const CONTRAINTES_DURES = [
        'capacite_salles' => 'Capacité totale des salles >= nombre de candidats',
        'enseignant_actif' => 'Enseignant actif requis',
        'non_double_affectation' => 'Pas de double affectation sur le même créneau',
        'conflit_examen' => 'Zéro conflit avec d’autres épreuves concurrentes',
        'conflit_emploi_du_temps' => 'Zéro conflit avec les créneaux réguliers (ScheduleSlot)',
        'minimum_principal' => 'Présence obligatoire d’un surveillant Principal',
        'minimum_adjoint' => 'Présence obligatoire d’au moins un Adjoint',
        'minimum_garde' => 'Présence obligatoire d’une Garde de réserve',
        'unicite_surveillant' => 'Unicité d’affectation sur une même épreuve',
    ];

    public const CONTRAINTES_SOUPLES = [
        'equilibre_charges' => 'Écart de charge pondérée minimal (cible delta <= 2.0)',
        'regroupement_3_jours' => 'Présence groupée sur exactement 3 jours',
        'equilibre_gardes' => 'Répartition équitable des gardes de réserve',
        'contiguite_temporelle' => 'Présence sur des jours contigus (J1-J2-J3 ou J3-J4-J5)',
    ];

    public function __construct(
        private ConflitEpreuveService $conflits,
        private SurveillanceService $surveillances,
        private SurveillanceConstraintResolver $contraintesResolver,
    ) {}

    /**
     * Contraintes effectives d'un appel : DTO fourni par l'appelant (Job
     * avec snapshot, tests) ou résolues pour le périmètre (défauts
     * système = constantes historiques si aucune configuration).
     */
    private function contraintes(array $options, ?SessionExamen $session = null, ?int $faculteId = null): ContraintesSurveillance
    {
        if (($options['contraintes'] ?? null) instanceof ContraintesSurveillance) {
            return $options['contraintes'];
        }

        return $this->contraintesResolver->resoudre($faculteId, $session?->annee_universitaire_id, $session?->id);
    }

    /**
     * Analyse des besoins et capacités pour une épreuve. Le
     * dimensionnement est calculé PAR SALLE (candidats réellement affectés
     * à chaque salle) puis agrégé : somme des besoins par salle, plancher
     * global et ratio global (défauts = règle historique).
     *
     * @param  Collection<int, Room>|null  $sallesPrechargees  lot keyé par id
     *                                                         (zéro requête, ex. cockpit ; null = 1 requête unique)
     * @return array{
     *   epreuve_id: int,
     *   candidats_reels: int,
     *   capacite_disponible: int,
     *   deficit_capacite: int,
     *   salles_ids: array<int, int>,
     *   nombre_salles: int,
     *   besoin_surveillants_actifs: int,
     *   besoin_gardes: int,
     *   besoin_total: int,
     *   besoins_salles: array<int, array{salle_id: int, code: string, candidats: int, besoin: int, minimum_applique: int, ratio_applique: int, source_regle: string}>,
     *   capacite_valide: bool,
     *   erreur_capacite: ?string
     * }
     */
    public function evaluerBesoinsEpreuve(Epreuve $epreuve, ?ContraintesSurveillance $contraintes = null, ?Collection $sallesPrechargees = null): array
    {
        $epreuve->loadMissing(['salle', 'groupe.etudiants', 'candidats.salle']);

        // 1. Nombre réel d'étudiants (candidats affectés ou capacité nécessaire ou effectif du groupe)
        $nbCandidats = max(
            $epreuve->candidats->count(),
            (int) ($epreuve->capacite_necessaire ?? 0),
            $epreuve->groupe?->etudiants?->count() ?? 0
        );

        // 2. Salles réelles affectées à l'épreuve (1 seule requête : modèles
        // + capacité + règles spécifiques de dimensionnement).
        $sallesIds = [];
        $capaciteTotale = 0;
        /** @var array<int, Room> $sallesParId */
        $sallesParId = [];

        $sallesCandidats = $epreuve->candidats->pluck('salle_id')->filter()->unique()->values()->all();
        if ($sallesCandidats !== []) {
            $sallesIds = $sallesCandidats;
            // Lot préchargé (cockpit) : zéro requête ; sinon 1 requête unique.
            $salles = $sallesPrechargees !== null
                ? $sallesPrechargees->only($sallesIds)
                : Room::whereIn('id', $sallesIds)->get(['id', 'code', 'capacite', 'min_surveillants', 'ratio_surveillants']);
            $sallesParId = $salles->keyBy('id')->all();
            $capaciteTotale = (int) $salles->sum('capacite');
        } elseif ($epreuve->salle_id !== null) {
            $sallesIds = [$epreuve->salle_id];
            $capaciteTotale = (int) ($epreuve->salle?->capacite ?? 0);
            if ($epreuve->salle !== null) {
                $sallesParId[$epreuve->salle->id] = $epreuve->salle;
            }
        }

        $nombreSalles = max(1, count($sallesIds));
        $deficit = max(0, $nbCandidats - $capaciteTotale);
        $capaciteValide = ($sallesIds === []) || ($deficit === 0);
        $erreurCapacite = null;

        if ($sallesIds !== [] && $deficit > 0) {
            $erreurCapacite = "Capacité insuffisante : {$nbCandidats} candidats pour {$capaciteTotale} places disponibles (déficit : {$deficit} places).";
        }

        // 3. Dimensionnement PAR SALLE sur les candidats réellement affectés
        // (jamais sur la capacité totale) : besoin_salle =
        // max(minimum_applicable, ceil(candidats_salle / ratio_applicable)),
        // agrégé en max(min_global, somme_salles, ceil(total / ratio)).
        $contraintes = $contraintes ?? new ContraintesSurveillance;

        // Candidats réellement ventilés par salle (sans double compte : un
        // candidat sans salle reste couvert par le plancher/ratio global).
        $candidatsParSalle = [];
        if ($sallesIds !== []) {
            foreach ($sallesIds as $salleId) {
                $candidatsParSalle[$salleId] = 0;
            }
            foreach ($epreuve->candidats as $candidat) {
                if ($candidat->salle_id !== null && array_key_exists((int) $candidat->salle_id, $candidatsParSalle)) {
                    $candidatsParSalle[(int) $candidat->salle_id]++;
                }
            }
            // Sans ventilation fine (aucun candidat rattaché) : tous les
            // candidats sont dans la salle unique de l'épreuve.
            if (array_sum($candidatsParSalle) === 0 && count($sallesIds) === 1) {
                $candidatsParSalle[$sallesIds[0]] = $nbCandidats;
            }
        }

        $besoinsSalles = [];
        $entreesSalles = [];
        foreach ($candidatsParSalle as $salleId => $candidatsSalle) {
            $salle = $sallesParId[$salleId] ?? null;
            $minimumSalle = $salle?->min_surveillants;
            $ratioSalle = $salle?->ratio_surveillants;
            $capaciteSalle = $salle?->capacite !== null ? (int) $salle->capacite : null;

            $besoinSalle = $contraintes->besoinSalle(
                $candidatsSalle,
                $minimumSalle !== null ? (int) $minimumSalle : null,
                $ratioSalle !== null ? (int) $ratioSalle : null,
                $capaciteSalle
            );
            $sourceRegle = $contraintes->sourceRegleSalle(
                $candidatsSalle,
                $minimumSalle !== null ? (int) $minimumSalle : null,
                $ratioSalle !== null ? (int) $ratioSalle : null,
                $capaciteSalle
            );

            $minimumApplique = match ($sourceRegle) {
                'salle' => (int) ($minimumSalle ?? $contraintes->minParSalle),
                'grande_salle' => max($contraintes->minParSalle, (int) ($contraintes->minSurveillantsGrandeSalle ?? $contraintes->minParSalle)),
                default => $contraintes->minParSalle,
            };
            $ratioApplique = ($ratioSalle !== null && $ratioSalle >= 1) ? (int) $ratioSalle : max(1, $contraintes->ratioEtudiants);

            $besoinsSalles[] = [
                'salle_id' => $salleId,
                'code' => $salle?->code ?? ('#'.$salleId),
                'capacite' => $capaciteSalle,
                'candidats' => $candidatsSalle,
                'besoin' => $besoinSalle,
                'minimum_applique' => $minimumApplique,
                'ratio_applique' => $ratioApplique,
                'source_regle' => $sourceRegle,
            ];
            $entreesSalles[] = [
                'salle_id' => $salleId,
                'candidats' => $candidatsSalle,
                'capacite_salle' => $capaciteSalle,
                'minimum_salle' => $minimumSalle !== null ? (int) $minimumSalle : null,
                'ratio_salle' => $ratioSalle !== null ? (int) $ratioSalle : null,
            ];
        }

        // Ordre déterministe (salle_id asc) : deux simulations identiques
        // rendent la même ventilation.
        usort($besoinsSalles, fn (array $a, array $b) => $a['salle_id'] <=> $b['salle_id']);

        $surveillantsActifsRequis = $entreesSalles === []
            ? $contraintes->besoinActifs($nbCandidats, $nombreSalles)
            : $contraintes->besoinActifsParSalles($nbCandidats, $entreesSalles);
        $gardesRequis = $contraintes->nbGardes;

        return [
            'epreuve_id' => $epreuve->id,
            'candidats_reels' => $nbCandidats,
            'capacite_disponible' => $capaciteTotale,
            'deficit_capacite' => $deficit,
            'salles_ids' => $sallesIds,
            'nombre_salles' => $nombreSalles,
            'besoin_surveillants_actifs' => $surveillantsActifsRequis,
            'besoin_gardes' => $gardesRequis,
            'besoin_total' => $surveillantsActifsRequis + $gardesRequis,
            'besoins_salles' => $besoinsSalles,
            'capacite_valide' => $capaciteValide,
            'erreur_capacite' => $erreurCapacite,
        ];
    }

    /**
     * Simulation complète de l'équilibrage d'une session (pure mémoire, batch, zéro N+1).
     *
     * @return array{
     *   session_id: int,
     *   statut: string, // 'OPTIMALE' | 'REALISABLE' | 'IMPOSSIBLE'
     *   message_statut: string,
     *   total_epreuves: int,
     *   total_surveillants_actifs: int,
     *   total_gardes_reserve: int,
     *   epreuves_couvertes_pct: float,
     *   gardes_couvertes_pct: float,
     *   conflits_detectes: int,
     *   problemes_capacite: int,
     *   indicateurs_charge: array{min: float, max: float, moyenne: float, ecart: float},
     *   repartition_jours: array{cible_3_jours: int, deux_jours: int, autres: int},
     *   bilan_enseignants: array<int, array<string, mixed>>,
     *   propositions_epreuves: array<int, array<string, mixed>>,
     *   anomalies: array<int, string>
     * }
     */
    public function simulerSession(SessionExamen $session, array $options = []): array
    {
        $faculteId = $options['faculte_id'] ?? request()->user()?->faculteRestreinteId();
        $contraintes = $this->contraintes($options, $session, $faculteId);

        // 1. Préchargement en lot de toutes les épreuves ouvertes de la session
        $epreuves = Epreuve::where('session_id', $session->id)
            ->where('statut', '!=', 'cancelled')
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->with([
                'matiere',
                'salle',
                'groupe.etudiants',
                'candidats.salle',
                'surveillants' => fn ($q) => $q->where('statut', '!=', 'annule'),
            ])
            ->orderBy('date')
            ->orderBy('heure_debut')
            ->orderBy('id')
            ->get();

        if ($epreuves->isEmpty()) {
            return [
                'session_id' => $session->id,
                'statut' => 'IMPOSSIBLE',
                'message_statut' => 'Aucune épreuve planifiée dans cette session.',
                'contraintes_dures_respectees' => false,
                'contraintes_souples_degradees' => [],
                'total_epreuves' => 0,
                'total_surveillants_actifs' => 0,
                'total_gardes_reserve' => 0,
                'epreuves_couvertes_pct' => 0.0,
                'gardes_couvertes_pct' => 0.0,
                'conflits_detectes' => 0,
                'problemes_capacite' => 0,
                'indicateurs_charge' => ['min' => 0, 'max' => 0, 'moyenne' => 0, 'ecart' => 0],
                'repartition_jours' => ['cible_3_jours' => 0, 'deux_jours' => 0, 'autres' => 0],
                'bilan_enseignants' => [],
                'propositions_epreuves' => [],
                'anomalies' => ['Aucune épreuve à affecter.'],
            ];
        }

        // 2. Préchargement des enseignants actifs éligibles
        $enseignants = Teacher::where('actif', true)
            ->when($faculteId !== null, fn ($q) => $q->where(
                fn ($w) => $w->whereHas('departement', fn ($d) => $d->where('faculte_id', $faculteId))
                    ->orWhereNull('departement_id')
            ))
            ->orderBy('nom')
            ->orderBy('prenom')
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        if ($enseignants->isEmpty()) {
            return [
                'session_id' => $session->id,
                'statut' => 'IMPOSSIBLE',
                'message_statut' => 'Aucun enseignant actif disponible.',
                'contraintes_dures_respectees' => false,
                'contraintes_souples_degradees' => [],
                'total_epreuves' => $epreuves->count(),
                'total_surveillants_actifs' => 0,
                'total_gardes_reserve' => 0,
                'epreuves_couvertes_pct' => 0.0,
                'gardes_couvertes_pct' => 0.0,
                'conflits_detectes' => 0,
                'problemes_capacite' => 0,
                'indicateurs_charge' => ['min' => 0, 'max' => 0, 'moyenne' => 0, 'ecart' => 0],
                'repartition_jours' => ['cible_3_jours' => 0, 'deux_jours' => 0, 'autres' => 0],
                'bilan_enseignants' => [],
                'propositions_epreuves' => [],
                'anomalies' => ['Aucun enseignant éligible.'],
            ];
        }

        // 3. Préchargement des créneaux de cours réguliers (ScheduleSlot) sur les jours concernés
        $joursSemaine = $epreuves->map(fn ($e) => strtolower($e->date->format('l')))->unique()->values()->all();
        $slotsGroupes = ScheduleSlot::whereIn('teacher_id', $enseignants->keys())
            ->whereIn('day_of_week', $joursSemaine)
            ->where('status', '!=', 'cancelled')
            ->with('matiere')
            ->orderBy('id')
            ->get()
            ->groupBy('teacher_id');

        // 4. Préchargement des autres épreuves concurrentes (hors session) sur les mêmes dates
        $datesSession = $epreuves->map(fn ($e) => $e->date->format('Y-m-d'))->unique()->values()->all();
        $autresEpreuves = Epreuve::whereIn(DB::raw('DATE(date)'), $datesSession)
            ->where('statut', '!=', 'cancelled')
            ->where('session_id', '!=', $session->id)
            ->with(['matiere', 'surveillants' => fn ($q) => $q->where('statut', '!=', 'annule')->whereNotNull('teacher_id')])
            ->orderBy('id')
            ->get();

        // 4b. Charge d'enseignement (1 agrégat ; voir chargesEnseignement).
        $chargeEns = $this->chargesEnseignement(
            $enseignants->keys()->all(),
            $session->annee_universitaire_id,
            $faculteId,
            $contraintes
        );

        // 5. Structure de suivi d'état des enseignants pendant la simulation
        $etatEnseignants = [];
        foreach ($enseignants as $id => $ens) {
            $etatEnseignants[$id] = [
                'id' => $id,
                'nom' => $ens->nomComplet(),
                'matricule' => $ens->matricule ?? '—',
                'examens_actifs' => 0,
                'gardes_reserve' => 0,
                'heures_totales' => 0.0,
                'charge_ponderee' => 0.0,
                'heures_enseignement' => $chargeEns['heures'][$id] ?? 0.0,
                'heures_enseignement_hetd' => $chargeEns['hetds'][$id] ?? 0.0,
                'charge_enseignement_detail' => $chargeEns['details'][$id] ?? [],
                'charge_enseignement_retenue' => $chargeEns['valeurs_retenues'][$id] ?? 0.0,
                'norme_enseignement' => $chargeEns['norme'][$id] ?? 0.0,
                'en_surcharge_enseignement' => $chargeEns['surcharges'][$id] ?? false,
                'jours_presence' => [], // dates 'Y-m-d'
                'creneaux_occupes' => [], // date => array de [debut, fin]
            ];
        }

        // Normes d'enseignement figées pour la simulation (charge
        // annuelle ne bougeant pas pendant l'exécution).
        $normesEnseignement = array_map(fn ($e) => $e['norme_enseignement'], $etatEnseignants);

        // Vérification capacité des salles et calcul des besoins
        $besoinsEpreuves = [];
        $anomalies = [];
        $nbProblemesCapacite = 0;

        foreach ($epreuves as $epreuve) {
            $besoin = $this->evaluerBesoinsEpreuve($epreuve, $contraintes);
            $besoinsEpreuves[$epreuve->id] = $besoin;

            if (! $besoin['capacite_valide']) {
                $nbProblemesCapacite++;
                $anomalies[] = "[Épreuve #{$epreuve->id} {$epreuve->code}] ".$besoin['erreur_capacite'];
            }
        }

        // Si capacité insuffisante bloquante, arrêt immédiat (pur :
        // aucun écriture, même pas d'audit — la simulation ne persiste
        // jamais rien, §8/§12).
        if ($nbProblemesCapacite > 0) {
            return [
                'session_id' => $session->id,
                'statut' => 'IMPOSSIBLE',
                'message_statut' => 'Capacité des salles insuffisante pour couvrir le nombre réel de candidats.',
                'contraintes_dures_respectees' => false,
                'contraintes_souples_degradees' => [],
                'total_epreuves' => $epreuves->count(),
                'total_surveillants_actifs' => 0,
                'total_gardes_reserve' => 0,
                'epreuves_couvertes_pct' => 0.0,
                'gardes_couvertes_pct' => 0.0,
                'conflits_detectes' => 0,
                'problemes_capacite' => $nbProblemesCapacite,
                'indicateurs_charge' => ['min' => 0, 'max' => 0, 'moyenne' => 0, 'ecart' => 0],
                'repartition_jours' => ['cible_3_jours' => 0, 'deux_jours' => 0, 'autres' => 0],
                'bilan_enseignants' => array_values($etatEnseignants),
                'propositions_epreuves' => [],
                'anomalies' => $anomalies,
            ];
        }

        $propositionsEpreuves = [];
        $totalActifsAssignes = 0;
        $totalGardesAssignes = 0;
        $totalActifsRequis = 0;
        $totalGardesRequis = 0;
        $nbConflitsDetectes = 0;

        foreach ($epreuves as $index => $epreuve) {
            if (! empty($options['progression_callback']) && is_callable($options['progression_callback'])) {
                ($options['progression_callback'])($index + 1, $epreuves->count(), $epreuve);
            }

            $besoin = $besoinsEpreuves[$epreuve->id];
            $dateStr = $epreuve->date->format('Y-m-d');
            $debutStr = substr((string) $epreuve->heure_debut, 0, 5);
            $finStr = substr((string) $epreuve->heure_fin, 0, 5);
            $dureeHeures = max(1.0, round(($epreuve->duree_minutes ?: 120) / 60, 2));

            $totalActifsRequis += $besoin['besoin_surveillants_actifs'];
            $totalGardesRequis += $besoin['besoin_gardes'];
            $codesSalles = collect($besoin['besoins_salles'] ?? [])->pluck('code', 'salle_id')->all();

            // Surveillants déjà existants sur cette épreuve
            $existants = $epreuve->surveillants->where('statut', '!=', 'annule');
            $existantsActifs = $existants->where('role', '!=', 'reserve');
            $existantsGardes = $existants->where('role', 'reserve');

            $propositionsSurveillants = [];
            $enseignantsUtilisesPourCetteEpreuve = [];

            // Comptabiliser les existants déjà affectés dans l'état de l'enseignant
            foreach ($existants as $survExistant) {
                if ($survExistant->teacher_id && isset($etatEnseignants[$survExistant->teacher_id])) {
                    $tId = $survExistant->teacher_id;
                    $enseignantsUtilisesPourCetteEpreuve[] = $tId;
                    $isGarde = ($survExistant->role === 'reserve');

                    if ($isGarde) {
                        $etatEnseignants[$tId]['gardes_reserve']++;
                        $etatEnseignants[$tId]['charge_ponderee'] += $contraintes->poidsGardeReserve;
                    } else {
                        $etatEnseignants[$tId]['examens_actifs']++;
                        $etatEnseignants[$tId]['charge_ponderee'] += $contraintes->poidsSurveillanceActive;
                    }

                    $etatEnseignants[$tId]['heures_totales'] += $dureeHeures;
                    $etatEnseignants[$tId]['jours_presence'][$dateStr] = true;
                    $etatEnseignants[$tId]['creneaux_occupes'][$dateStr][] = [$debutStr, $finStr];

                    $propositionsSurveillants[] = [
                        'teacher_id' => $tId,
                        'nom' => $etatEnseignants[$tId]['nom'],
                        'role' => $survExistant->role,
                        'salle_id' => $survExistant->salle_id,
                        'salle_code' => $codesSalles[$survExistant->salle_id] ?? null,
                        'existant' => true,
                    ];
                }
            }

            $nbActifsManquants = max(0, $besoin['besoin_surveillants_actifs'] - $existantsActifs->count());
            $nbGardesManquants = max(0, $besoin['besoin_gardes'] - $existantsGardes->count());

            // A. Affectation des surveillants actifs manquants (1 Principal, 1 Adjoint, etc.).
            // Répartition déterministe des postes vers les salles ayant des besoins non pourvus.
            $fileSalles = [];
            foreach ($besoin['besoins_salles'] ?? [] as $bSalle) {
                $sId = $bSalle['salle_id'];
                $code = $bSalle['code'];
                $existantsDansSalle = $existantsActifs->where('salle_id', $sId)->count();
                $manquantsSalle = max(0, $bSalle['besoin'] - $existantsDansSalle);
                for ($m = 0; $m < $manquantsSalle; $m++) {
                    $fileSalles[] = ['salle_id' => $sId, 'code' => $code];
                }
            }

            // Le premier actif attribué est Principal UNIQUEMENT si aucun
            // principal n'existe déjà (les existants peuvent ne pas en avoir :
            // saisie manuelle antérieure, principal désaffecté).
            for ($i = 0; $i < $nbActifsManquants; $i++) {
                $aDejaPrincipal = $existantsActifs->contains('role', 'principal')
                    || collect($propositionsSurveillants)->contains(fn ($p) => ($p['role'] ?? null) === 'principal');
                $roleCandidat = ($i === 0 && ! $aDejaPrincipal) ? 'principal' : 'adjoint';
                $meilleur = $this->trouverMeilleurEnseignant(
                    $etatEnseignants,
                    $epreuve,
                    $dateStr,
                    $debutStr,
                    $finStr,
                    $dureeHeures,
                    false, // pas garde
                    $enseignantsUtilisesPourCetteEpreuve,
                    $slotsGroupes,
                    $autresEpreuves,
                    $contraintes,
                    $normesEnseignement
                );

                if ($meilleur !== null) {
                    $tId = $meilleur['id'];
                    $enseignantsUtilisesPourCetteEpreuve[] = $tId;
                    $etatEnseignants[$tId]['examens_actifs']++;
                    $etatEnseignants[$tId]['charge_ponderee'] += $contraintes->poidsSurveillanceActive;
                    $etatEnseignants[$tId]['heures_totales'] += $dureeHeures;
                    $etatEnseignants[$tId]['jours_presence'][$dateStr] = true;
                    $etatEnseignants[$tId]['creneaux_occupes'][$dateStr][] = [$debutStr, $finStr];
                    $totalActifsAssignes++;

                    $cibleSalle = array_shift($fileSalles);
                    $salleIdAffectee = $cibleSalle['salle_id'] ?? $epreuve->salle_id;
                    $salleCodeAffectee = $cibleSalle['code'] ?? ($codesSalles[$salleIdAffectee] ?? $epreuve->salle?->code ?? null);

                    $propositionsSurveillants[] = [
                        'teacher_id' => $tId,
                        'nom' => $etatEnseignants[$tId]['nom'],
                        'role' => $roleCandidat,
                        'salle_id' => $salleIdAffectee,
                        'salle_code' => $salleCodeAffectee,
                        'existant' => false,
                    ];
                } else {
                    $nbConflitsDetectes++;
                    $causes = $this->diagnostiquerCarence(
                        $etatEnseignants,
                        $epreuve,
                        $dateStr,
                        $debutStr,
                        $finStr,
                        $enseignantsUtilisesPourCetteEpreuve,
                        $slotsGroupes,
                        $autresEpreuves,
                        $contraintes
                    );
                    $anomalies[] = "[Épreuve #{$epreuve->id} {$epreuve->code}] Aucun enseignant disponible pour le poste de surveillant actif #".($i + 1)." ({$causes}). ".$this->diagnosticBesoinsSalles($besoin, $contraintes, count($etatEnseignants));
                }
            }

            // B. Affectation du garde / réserve manquant
            for ($i = 0; $i < $nbGardesManquants; $i++) {
                $meilleurGarde = $this->trouverMeilleurEnseignant(
                    $etatEnseignants,
                    $epreuve,
                    $dateStr,
                    $debutStr,
                    $finStr,
                    $dureeHeures,
                    true, // rôle réserve
                    $enseignantsUtilisesPourCetteEpreuve,
                    $slotsGroupes,
                    $autresEpreuves,
                    $contraintes,
                    $normesEnseignement
                );

                if ($meilleurGarde !== null) {
                    $tId = $meilleurGarde['id'];
                    $enseignantsUtilisesPourCetteEpreuve[] = $tId;
                    $etatEnseignants[$tId]['gardes_reserve']++;
                    $etatEnseignants[$tId]['charge_ponderee'] += $contraintes->poidsGardeReserve;
                    $etatEnseignants[$tId]['heures_totales'] += $dureeHeures;
                    $etatEnseignants[$tId]['jours_presence'][$dateStr] = true;
                    $etatEnseignants[$tId]['creneaux_occupes'][$dateStr][] = [$debutStr, $finStr];
                    $totalGardesAssignes++;

                    $propositionsSurveillants[] = [
                        'teacher_id' => $tId,
                        'nom' => $etatEnseignants[$tId]['nom'],
                        'role' => 'reserve',
                        'salle_id' => $epreuve->salle_id,
                        'salle_code' => $epreuve->salle?->code,
                        'existant' => false,
                    ];
                } else {
                    $nbConflitsDetectes++;
                    $causes = $this->diagnostiquerCarence(
                        $etatEnseignants,
                        $epreuve,
                        $dateStr,
                        $debutStr,
                        $finStr,
                        $enseignantsUtilisesPourCetteEpreuve,
                        $slotsGroupes,
                        $autresEpreuves,
                        $contraintes
                    );
                    $anomalies[] = "[Épreuve #{$epreuve->id} {$epreuve->code}] Aucun enseignant disponible pour le poste de garde / réserve ({$causes}).";
                }
            }

            $totalActifsSurEpreuve = count(array_filter($propositionsSurveillants, fn ($p) => $p['role'] !== 'reserve'));
            $totalGardesSurEpreuve = count(array_filter($propositionsSurveillants, fn ($p) => $p['role'] === 'reserve'));

            // Ventilation des nouveaux actifs par salle (point 2) : chaque
            // salle reçoit ses `besoin` surveillants identifiés.
            $this->ventilerPropositionsParSalle(
                $propositionsSurveillants,
                $besoin,
                $epreuve->salle_id !== null ? (int) $epreuve->salle_id : null,
                $epreuve->salle?->code
            );

            $propositionsEpreuves[] = [
                'epreuve_id' => $epreuve->id,
                'code' => $epreuve->code,
                'matiere' => $epreuve->matiere?->nom ?? '—',
                'date' => $dateStr,
                'horaire' => "{$debutStr} → {$finStr}",
                'salle' => $epreuve->salle?->code ?? ($besoin['nombre_salles'] > 1 ? "Multi-salles ({$besoin['nombre_salles']})" : 'Non assignée'),
                'surveillants' => $propositionsSurveillants,
                'besoins_salles' => $besoin['besoins_salles'] ?? [],
                'besoin_surveillants_actifs' => $besoin['besoin_surveillants_actifs'],
                'besoin_gardes' => $besoin['besoin_gardes'],
                'couverture_actifs' => "{$totalActifsSurEpreuve} / {$besoin['besoin_surveillants_actifs']}",
                'couverture_garde' => "{$totalGardesSurEpreuve} / {$besoin['besoin_gardes']}",
                'complet' => ($totalActifsSurEpreuve >= $besoin['besoin_surveillants_actifs']) && ($totalGardesSurEpreuve >= $besoin['besoin_gardes']),
            ];
        }

        // 7. Seconde passe d'optimisation globale (§11) : rééquilibrage de charge et de gardes
        $this->optimiserHeuristiqueGlobalement(
            $propositionsEpreuves,
            $etatEnseignants,
            $epreuves->keyBy('id'),
            $slotsGroupes,
            $autresEpreuves,
            $contraintes
        );

        // 8. Calculs d'indicateurs de répartition et d'équité
        $bilanEnseignants = [];
        $charges = [];
        $nbTroisJours = 0;
        $nbDeuxJours = 0;
        $nbAutresJours = 0;

        $nbDepassementsJours = 0;
        foreach ($etatEnseignants as $ensData) {
            $nbJours = count($ensData['jours_presence']);
            $charge = round($ensData['charge_ponderee'], 1);
            $charges[] = $charge;

            if ($nbJours > $contraintes->maxJours) {
                $nbDepassementsJours++;
            }

            if ($nbJours === $contraintes->maxJours) {
                $nbTroisJours++;
            } elseif ($nbJours === 2) {
                $nbDeuxJours++;
            } else {
                $nbAutresJours++;
            }

            $bilanEnseignants[] = [
                'teacher_id' => $ensData['id'],
                'nom' => $ensData['nom'],
                'matricule' => $ensData['matricule'],
                'examens' => $ensData['examens_actifs'],
                'gardes' => $ensData['gardes_reserve'],
                'jours' => $nbJours,
                'heures' => round($ensData['heures_totales'], 1),
                'charge' => $charge,
                'heures_enseignement' => $ensData['heures_enseignement'] ?? 0.0,
                'heures_enseignement_hetd' => $ensData['heures_enseignement_hetd'] ?? 0.0,
                'charge_enseignement_detail' => $ensData['charge_enseignement_detail'] ?? [],
                'charge_enseignement_retenue' => $ensData['charge_enseignement_retenue'] ?? 0.0,
                'norme_enseignement' => $ensData['norme_enseignement'] ?? 0.0,
                'en_surcharge_enseignement' => $ensData['en_surcharge_enseignement'] ?? false,
                // Par enseignant (diagnostic §6) : « a des données »
                // signifie des heures enregistrées POUR CET enseignant —
                // jamais la couverture globale du lot, qui reste exposée
                // dans statistiques_enseignement.couverture.
                'donnees_charge_completes' => ($ensData['heures_enseignement'] ?? 0.0) > 0.0,
            ];
        }

        // Tri par charge décroissante
        usort($bilanEnseignants, fn ($a, $b) => $b['charge'] <=> $a['charge'] ?: strcmp($a['nom'], $b['nom']));

        $chargeMin = $charges !== [] ? min($charges) : 0.0;
        $chargeMax = $charges !== [] ? max($charges) : 0.0;
        $chargeMoyenne = $charges !== [] ? round(array_sum($charges) / count($charges), 2) : 0.0;
        $ecartCharge = round($chargeMax - $chargeMin, 1);

        $couvertureActifsPct = $totalActifsRequis > 0 ? round(($totalActifsAssignes / $totalActifsRequis) * 100, 1) : 100.0;
        $couvertureGardesPct = $totalGardesRequis > 0 ? round(($totalGardesAssignes / $totalGardesRequis) * 100, 1) : 100.0;

        // Détermination des contraintes dures et souples (§11)
        $contraintesDuresRespectees = ($nbConflitsDetectes === 0 && $nbProblemesCapacite === 0);
        $contraintesSouplesDegradees = [];

        if ($nbDepassementsJours > 0) {
            $contraintesSouplesDegradees[] = "Dépassement de la limite souple de présence ({$nbDepassementsJours} enseignant(s) au-delà de {$contraintes->maxJours} jours).";
        } elseif ($nbAutresJours > 0 && $contraintes->maxJours >= 3) {
            $contraintesSouplesDegradees[] = "Présence sur {$contraintes->maxJours} jours non totalement satisfaite ({$nbAutresJours} enseignant(s) hors cible de {$contraintes->maxJours} jours).";
        }
        if ($ecartCharge > 2.0) {
            $contraintesSouplesDegradees[] = "Écart de charge élevé ({$ecartCharge} > cible de 2.0).";
        }
        if ($contraintes->actifChargeEnseignement && ($chargeEns['sans_donnees'] ?? 0) > 0) {
            $contraintesSouplesDegradees[] = "Données de charge d'enseignement partielles ({$chargeEns['sans_donnees']} enseignant(s) sans affectation active enregistrée) : le critère s'applique aux données connues sans extrapolation arbitraire.";
        }

        // Détermination du statut global (§10/§11/§14)
        if (! $contraintesDuresRespectees) {
            $statut = 'IMPOSSIBLE';
            $nbEnseignantsDispos = $enseignants->count();
            $messageStatut = "Affectation impossible. {$epreuves->count()} épreuve(s) nécessitent au minimum {$totalActifsRequis} surveillant(s) actif(s) et {$totalGardesRequis} garde(s). "
                .($nbConflitsDetectes > 0 ? "Déficit de personnel : {$nbConflitsDetectes} poste(s) non pourvu(s) avec {$nbEnseignantsDispos} enseignant(s) mobilisable(s). " : '')
                .($nbProblemesCapacite > 0 ? "{$nbProblemesCapacite} problème(s) de capacité de salle détecté(s)." : '');
        } elseif ($contraintesSouplesDegradees !== []) {
            $statut = 'REALISABLE';
            $messageStatut = 'Affectation réalisable avec dégradation contrôlée : contraintes dures 100 % respectées, mais '.implode(' ', $contraintesSouplesDegradees);
        } else {
            $statut = 'OPTIMALE';
            $messageStatut = 'Répartition optimale : 100 % d’épreuves et gardes couvertes, équilibre 3 jours parfait et charge équitable.';
        }

        // Simulation pure (§8/§12) : aucun écriture, aucun audit métier.
        // La traçabilité vit dans appliquerSession (acte réel).
        return [
            'session_id' => $session->id,
            'statut' => $statut,
            'message_statut' => $messageStatut,
            'contraintes_dures_respectees' => $contraintesDuresRespectees,
            'contraintes_souples_degradees' => $contraintesSouplesDegradees,
            'total_epreuves' => $epreuves->count(),
            'total_surveillants_actifs' => $totalActifsAssignes,
            'total_gardes_reserve' => $totalGardesAssignes,
            'epreuves_couvertes_pct' => $couvertureActifsPct,
            'gardes_couvertes_pct' => $couvertureGardesPct,
            'conflits_detectes' => $nbConflitsDetectes,
            'problemes_capacite' => $nbProblemesCapacite,
            'indicateurs_charge' => [
                'min' => $chargeMin,
                'max' => $chargeMax,
                'moyenne' => $chargeMoyenne,
                'ecart' => $ecartCharge,
            ],
            'repartition_jours' => [
                'cible_3_jours' => $nbTroisJours,
                'deux_jours' => $nbDeuxJours,
                'autres' => $nbAutresJours,
            ],
            'statistiques_enseignement' => [
                'actif' => $contraintes->actifChargeEnseignement,
                'mode_calcul' => $contraintes->modeCalculChargeEnseignement,
                'poids' => $contraintes->poidsChargeEnseignementEffectif(),
                'seuil_surcharge' => $contraintes->seuilSurchargeEnseignement,
                'max_surveillances' => $contraintes->maxSurveillancesEnseignant,
                'couverture' => $chargeEns['couverture'] ?? 1.0,
                'sans_donnees' => $chargeEns['sans_donnees'] ?? 0,
                'nb_surcharges' => count(array_filter($chargeEns['surcharges'] ?? [])),
            ],
            'bilan_enseignants' => $bilanEnseignants,
            'propositions_epreuves' => $propositionsEpreuves,
            'anomalies' => $anomalies,
        ];
    }

    /**
     * Application transactionnelle avec verrouillage pessimiste et revalidation (§15-16).
     * Tout-ou-rien : la moindre erreur métier annule l'ensemble (voie synchrone).
     *
     * @return array{assignes: int, epreuves_mises_a_jour: int, session_id: int}
     */
    public function appliquerSession(SessionExamen $session, array $proposition, int $auteurId, ?callable $progressionCallback = null): array
    {
        if (($proposition['statut'] ?? '') === 'IMPOSSIBLE') {
            throw new DomainException($proposition['message_statut'] ?? 'Application impossible : la simulation comporte des déficits bloquants.');
        }

        return DB::transaction(function () use ($session, $proposition, $auteurId, $progressionCallback) {
            $totalAssignes = 0;
            $epreuvesTraitees = 0;

            foreach ($proposition['propositions_epreuves'] ?? [] as $epreuveProp) {
                /** @var Epreuve $epreuveVerrouillee */
                $epreuveVerrouillee = Epreuve::whereKey($epreuveProp['epreuve_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($epreuveVerrouillee->estVerrouillee()) {
                    throw new DomainException("L'épreuve #{$epreuveVerrouillee->id} ({$epreuveVerrouillee->code}) est verrouillée : application interdite.");
                }

                $epreuvesTraitees++;
                $totalAssignes += $this->appliquerPropositionEpreuve($epreuveVerrouillee, $epreuveProp, $auteurId);

                if ($progressionCallback !== null) {
                    $progressionCallback($epreuvesTraitees, $totalAssignes);
                }
            }

            IntegrationLog::tracer(
                'surveillance',
                'internal',
                'surveillance.balance.applied',
                'completed',
                json_encode([
                    'session_id' => $session->id,
                    'total_assignes' => $totalAssignes,
                    'epreuves_mises_a_jour' => $epreuvesTraitees,
                ], JSON_THROW_ON_ERROR),
                200,
                null,
                null,
                (string) Str::uuid(),
                'SESS-'.$session->id
            );

            EvaluationChanged::dispatch('surveillance.balance.applied', $session->id);

            return [
                'session_id' => $session->id,
                'assignes' => $totalAssignes,
                'epreuves_mises_a_jour' => $epreuvesTraitees,
            ];
        });
    }

    /**
     * Corps d'application d'UNE proposition d'épreuve : postes manquants
     * créés via `SurveillanceService` (mêmes gardes, même idempotence).
     * L'appelant gère la transaction et la politique d'erreur.
     */
    private function appliquerPropositionEpreuve(Epreuve $epreuveVerrouillee, array $epreuveProp, int $auteurId): int
    {
        $crees = 0;

        foreach ($epreuveProp['surveillants'] ?? [] as $survProp) {
            if (! empty($survProp['existant'])) {
                continue; // Ne pas recréer les surveillances déjà existantes
            }

            $teacherId = (int) $survProp['teacher_id'];
            $role = $survProp['role'] ?? 'surveillant';

            // Idempotence : vérification qu'il n'a pas été affecté entre-temps
            $dejaPresent = $epreuveVerrouillee->surveillants()
                ->where('statut', '!=', 'annule')
                ->where('teacher_id', $teacherId)
                ->exists();

            if ($dejaPresent) {
                continue;
            }

            // Garde-fou : une proposition falsifiée hors des salles de
            // l'épreuve est repliée sur la salle principale (fail-safe :
            // jamais de fuite vers une salle étrangère, jamais d'échec).
            $salleProposee = isset($survProp['salle_id']) ? (int) $survProp['salle_id'] : null;
            $sallesAutorisees = array_filter(array_merge(
                [$epreuveVerrouillee->salle_id !== null ? (int) $epreuveVerrouillee->salle_id : null],
                $epreuveVerrouillee->candidats()->whereNotNull('salle_id')->distinct()->pluck('salle_id')->map(fn ($id) => (int) $id)->all()
            ), fn ($id) => $id !== null);

            $this->surveillances->assigner($epreuveVerrouillee, [
                'teacher_id' => $teacherId,
                'role' => $role,
                'salle_id' => ($salleProposee !== null && in_array($salleProposee, $sallesAutorisees, true))
                    ? $salleProposee
                    : $epreuveVerrouillee->salle_id,
            ], $auteurId);

            $crees++;
        }

        return $crees;
    }

    /**
     * Application par épreuve, une transaction chacune (voie asynchrone/Job).
     *
     * Stratégie batch cohérente (miroir des tranches `ExecuterAffectationMasseLot`) :
     * une épreuve en échec métier (`DomainException` : verrou posée entre-temps,
     * conflit concurrent) est comptée en avertissement et n'annule PAS les
     * épreuves déjà appliquées. Seule une erreur technique interrompt le lot
     * (la transaction de l'épreuve courante est annulée, le Job marque FAILED ;
     * relancer est idempotent grâce au contrôle `dejaPresent`).
     *
     * @return array{assignes: int, epreuves_traitees: int, epreuves_ignorees: int, avertissements: array<int, string>, session_id: int}
     */
    public function appliquerSessionParLots(SessionExamen $session, array $proposition, int $auteurId, ?callable $avancement = null): array
    {
        if (($proposition['statut'] ?? '') === 'IMPOSSIBLE') {
            throw new DomainException($proposition['message_statut'] ?? 'Application impossible : la simulation comporte des déficits bloquants.');
        }

        $assignes = 0;
        $traitees = 0;
        $ignorees = 0;
        $avertissements = [];

        foreach ($proposition['propositions_epreuves'] ?? [] as $epreuveProp) {
            try {
                $crees = DB::transaction(function () use ($epreuveProp, $auteurId) {
                    /** @var Epreuve $epreuveVerrouillee */
                    $epreuveVerrouillee = Epreuve::whereKey($epreuveProp['epreuve_id'])
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($epreuveVerrouillee->estVerrouillee()) {
                        throw new DomainException("L'épreuve #{$epreuveVerrouillee->id} ({$epreuveVerrouillee->code}) est verrouillée : application interdite.");
                    }

                    return $this->appliquerPropositionEpreuve($epreuveVerrouillee, $epreuveProp, $auteurId);
                });
                $assignes += $crees;
            } catch (DomainException $e) {
                $ignorees++;
                $avertissements[] = "[Épreuve #{$epreuveProp['epreuve_id']}] ".$e->getMessage();
                if (count($avertissements) > 50) {
                    $avertissements = array_slice($avertissements, 0, 50);
                }
            }

            $traitees++;
            if ($avancement !== null) {
                $avancement($traitees, $assignes, $ignorees);
            }
        }

        IntegrationLog::tracer(
            'surveillance',
            'internal',
            'surveillance.balance.applied_lots',
            'completed',
            json_encode([
                'session_id' => $session->id,
                'total_assignes' => $assignes,
                'epreuves_traitees' => $traitees,
                'epreuves_ignorees' => $ignorees,
            ], JSON_THROW_ON_ERROR),
            200,
            null,
            null,
            (string) Str::uuid(),
            'SESS-'.$session->id
        );

        EvaluationChanged::dispatch('surveillance.balance.applied', $session->id);

        return [
            'session_id' => $session->id,
            'assignes' => $assignes,
            'epreuves_traitees' => $traitees,
            'epreuves_ignorees' => $ignorees,
            'avertissements' => $avertissements,
        ];
    }

    /**
     * Optimisation réelle de l'existant : transferts de gardes actives
     * depuis les enseignants surchargés vers les sous-chargés, sans
     * introduire aucun conflit. Contrairement à appliquerSession (qui ne
     * comble que les manques), cette méthode DÉPLACE des affectations
     * existantes déjà persistées — c'est elle qui rend le bouton
     * « Optimiser » effectif sur une session déjà couverte.
     *
     * Mêmes critères que la simulation (poids, cible 3 jours, conflits
     * jour-exact, unicité) : aucune règle parallèle, simple application
     * aux lignes existantes, en transaction avec verrous pessimistes.
     *
     * @return array{session_id: int, analysees: int, ameliorees: int, ecart_avant: float, ecart_apres: float, charge_min: float, charge_max: float, charge_moyenne: float}
     */
    public function optimiserGlobalement(SessionExamen $session, array $options = [], int $auteurId = 0): array
    {
        return $this->optimiserSession($session, $options, $auteurId);
    }

    /**
     * Optimisation réelle de l'existant : transferts de gardes actives
     * depuis les enseignants surchargés vers les sous-chargés, sans
     * introduire aucun conflit.
     *
     * @param  array{faculte_id?: int|null}  $options
     * @return array{session_id: int, analysees: int, ameliorees: int, ecart_avant: float, ecart_apres: float, charge_min: float, charge_max: float, charge_moyenne: float}
     */
    public function optimiserSession(SessionExamen $session, array $options = [], int $auteurId = 0): array
    {
        $faculteId = $options['faculte_id'] ?? request()->user()?->faculteRestreinteId();
        $contraintes = $this->contraintes($options, $session, $faculteId);

        return DB::transaction(function () use ($session, $faculteId, $contraintes) {
            $epreuves = Epreuve::where('session_id', $session->id)
                ->where('statut', '!=', 'cancelled')
                ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
                ->with([
                    'matiere',
                    'surveillants' => fn ($q) => $q->whereIn('statut', ['assigne', 'confirme'])->with('enseignant'),
                ])
                ->orderBy('date')
                ->orderBy('heure_debut')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($epreuves->isEmpty()) {
                throw new DomainException('Session sans épreuves : rien à optimiser.');
            }

            // Enseignants éligibles du périmètre (actifs uniquement).
            $enseignants = Teacher::where('actif', true)
                ->when($faculteId !== null, fn ($q) => $q->where(
                    fn ($w) => $w->whereHas('departement', fn ($d) => $d->where('faculte_id', $faculteId))
                        ->orWhereNull('departement_id')
                ))
                ->orderBy('id')
                ->get()
                ->keyBy('id');

            if ($enseignants->isEmpty()) {
                throw new DomainException('Aucun enseignant actif disponible.');
            }

            // État courant : charges pondérées + jours + créneaux (périmètre session).
            $charges = [];
            $jours = [];
            $creneaux = [];
            $nbPostes = [];
            foreach ($enseignants as $id => $ens) {
                $charges[$id] = 0.0;
                $jours[$id] = [];
                $creneaux[$id] = [];
                $nbPostes[$id] = 0;
            }
            $gardesActives = 0;
            foreach ($epreuves as $epreuve) {
                $dateStr = $epreuve->date->format('Y-m-d');
                $debutStr = substr((string) $epreuve->heure_debut, 0, 5);
                $finStr = substr((string) $epreuve->heure_fin, 0, 5);
                foreach ($epreuve->surveillants as $surv) {
                    if ($surv->teacher_id === null || ! isset($charges[(int) $surv->teacher_id])) {
                        continue;
                    }
                    $tId = (int) $surv->teacher_id;
                    $charges[$tId] += $surv->role === 'reserve' ? $contraintes->poidsGardeReserve : $contraintes->poidsSurveillanceActive;
                    $jours[$tId][$dateStr] = true;
                    $creneaux[$tId][$dateStr][] = [$debutStr, $finStr];
                    $nbPostes[$tId]++;
                    $gardesActives++;
                }
            }

            if ($gardesActives === 0) {
                throw new DomainException('Aucune affectation existante à optimiser.');
            }

            // Slots groupés par enseignant (filtre jour appliqué par épreuve, cf. simulerSession).
            $joursSemaine = $epreuves->map(fn ($e) => strtolower($e->date->format('l')))->unique()->values()->all();
            $slotsGroupes = ScheduleSlot::whereIn('teacher_id', array_keys($charges))
                ->whereIn('day_of_week', $joursSemaine)
                ->where('status', '!=', 'cancelled')
                ->orderBy('id')
                ->get()
                ->groupBy('teacher_id');

            // Autres épreuves hors session sur les mêmes dates (conflits externes).
            $datesSession = $epreuves->map(fn ($e) => $e->date->format('Y-m-d'))->unique()->values()->all();
            $autresEpreuves = Epreuve::whereIn(DB::raw('DATE(date)'), $datesSession)
                ->where('statut', '!=', 'cancelled')
                ->where('session_id', '!=', $session->id)
                ->with(['surveillants' => fn ($q) => $q->whereIn('statut', ['assigne', 'confirme'])->whereNotNull('teacher_id')])
                ->orderBy('id')
                ->get();

            // Charge d'enseignement (1 agrégat) : la sélection des
            // transferts se fait au coût total (surveillance + terme
            // normalisé), l'écart affiché restant celui des surveillances.
            $chargeEns = $this->chargesEnseignement(
                array_keys($charges),
                $session->annee_universitaire_id,
                $faculteId,
                $contraintes
            );
            $coutTotal = [];
            foreach ($charges as $id => $charge) {
                $coutTotal[$id] = $this->coutTotal(
                    $charge,
                    $chargeEns['norme'][$id] ?? 0.0,
                    $contraintes,
                    $chargeEns['surcharges'][$id] ?? false
                );
            }

            $ecartCourant = function () use (&$charges): float {
                return round(max($charges) - min($charges), 1);
            };
            $ecartAvant = $ecartCourant();
            $ameliorees = 0;

            for ($iteration = 0; $iteration < 50; $iteration++) {
                $maxTId = array_keys($coutTotal, max($coutTotal))[0];
                $minTId = array_keys($coutTotal, min($coutTotal))[0];

                if (($coutTotal[$maxTId] - $coutTotal[$minTId]) <= 1.5) {
                    break;
                }

                $transfertEffectue = false;

                foreach ($epreuves as $epreuve) {
                    if ($epreuve->estVerrouillee()) {
                        continue;
                    }

                    // Garde active du surchargé sur cette épreuve (réserves
                    // d'abord pour stabiliser les principaux, puis adjoints,
                    // puis principal en dernier recours).
                    $candidate = $epreuve->surveillants
                        ->where('teacher_id', $maxTId)
                        ->sortBy(fn ($s) => $s->role === 'reserve' ? 0 : ($s->role === 'principal' ? 2 : 1))
                        ->first();

                    if ($candidate === null) {
                        continue;
                    }

                    if (! $this->peutReprendreGarde($epreuve, $candidate, $minTId, $enseignants, $charges, $jours, $creneaux, $slotsGroupes, $autresEpreuves, $contraintes, $nbPostes)) {
                        continue;
                    }

                    $poids = $candidate->role === 'reserve' ? $contraintes->poidsGardeReserve : $contraintes->poidsSurveillanceActive;
                    $dateStr = $epreuve->date->format('Y-m-d');
                    $debutStr = substr((string) $epreuve->heure_debut, 0, 5);
                    $finStr = substr((string) $epreuve->heure_fin, 0, 5);

                    Surveillance::whereKey($candidate->id)->lockForUpdate()->firstOrFail()
                        ->update(['teacher_id' => $minTId]);

                    // La collection eager-loadée vit encore en mémoire pour
                    // les itérations suivantes : la resynchroniser, sinon le
                    // même poste serait déplacé deux fois (dérive DB/mémoire).
                    $candidate->teacher_id = $minTId;

                    $charges[$maxTId] = round($charges[$maxTId] - $poids, 2);
                    $charges[$minTId] = round($charges[$minTId] + $poids, 2);
                    $nbPostes[$maxTId] = max(0, $nbPostes[$maxTId] - 1);
                    $nbPostes[$minTId]++;
                    $coutTotal[$maxTId] = $this->coutTotal($charges[$maxTId], $chargeEns['norme'][$maxTId] ?? 0.0, $contraintes, $chargeEns['surcharges'][$maxTId] ?? false);
                    $coutTotal[$minTId] = $this->coutTotal($charges[$minTId], $chargeEns['norme'][$minTId] ?? 0.0, $contraintes, $chargeEns['surcharges'][$minTId] ?? false);
                    $jours[$minTId][$dateStr] = true;
                    $creneaux[$minTId][$dateStr][] = [$debutStr, $finStr];
                    foreach (($creneaux[$maxTId][$dateStr] ?? []) as $idx => [$cDeb, $cFin]) {
                        if ($cDeb === $debutStr && $cFin === $finStr) {
                            unset($creneaux[$maxTId][$dateStr][$idx]);
                            break;
                        }
                    }
                    if (empty($creneaux[$maxTId][$dateStr])) {
                        unset($creneaux[$maxTId][$dateStr], $jours[$maxTId][$dateStr]);
                    }

                    $ameliorees++;
                    $transfertEffectue = true;
                    break;
                }

                if (! $transfertEffectue) {
                    break;
                }
            }

            $ecartApres = $ecartCourant();
            $chargeMin = round(min($charges), 1);
            $chargeMax = round(max($charges), 1);
            $chargeMoyenne = round(array_sum($charges) / max(1, count($charges)), 2);

            IntegrationLog::tracer(
                'surveillance',
                'internal',
                'surveillance.optimized',
                'completed',
                json_encode([
                    'session_id' => $session->id,
                    'analysees' => $epreuves->count(),
                    'ameliorees' => $ameliorees,
                    'ecart_avant' => $ecartAvant,
                    'ecart_apres' => $ecartApres,
                ], JSON_THROW_ON_ERROR),
                200,
                null,
                null,
                (string) Str::uuid(),
                'SESS-'.$session->id
            );

            EvaluationChanged::dispatch('surveillance.optimisee', $session->id);

            return [
                'session_id' => $session->id,
                'analysees' => $epreuves->count(),
                'ameliorees' => $ameliorees,
                'ecart_avant' => $ecartAvant,
                'ecart_apres' => $ecartApres,
                'charge_min' => $chargeMin,
                'charge_max' => $chargeMax,
                'charge_moyenne' => $chargeMoyenne,
            ];
        });
    }

    /**
     * Garde d'éligibilité ponctuelle pour un transfert d'optimisation :
     * MÊMES critères que trouverMeilleurEnseignant (actif, unicité sur
     * l'épreuve, conflit interne, créneau du jour exact, autres épreuves,
     * cible 3 jours) — appliqués à une garde existante au lieu d'une
     * proposition en mémoire.
     *
     * @param  array<int, float>  $charges
     * @param  array<int, array<string, bool>>  $jours
     * @param  array<int, array<string, array<int, array{0: string, 1: string}>>>  $creneaux
     */
    private function peutReprendreGarde(
        Epreuve $epreuve,
        Surveillance $garde,
        int $candidatId,
        Collection $enseignants,
        array $charges,
        array $jours,
        array $creneaux,
        Collection $slotsGroupes,
        Collection $autresEpreuves,
        ?ContraintesSurveillance $contraintes = null,
        array $nbPostes = []
    ): bool {
        $contraintes ??= new ContraintesSurveillance;
        if ($candidatId === (int) $garde->teacher_id) {
            return false;
        }

        $candidat = $enseignants->get($candidatId);
        if ($candidat === null || ! $candidat->actif) {
            return false;
        }

        // Plafond maximal de surveillances (miroir de la règle 4c de
        // trouverMeilleurEnseignant) : un transfert d'optimisation ne
        // doit JAMAIS contourner la limite explicite de l'admin. La
        // comparaison porte sur le NOMBRE DE POSTES (jamais sur la
        // charge pondérée : une réserve à 0,5 ne doit pas passer sous
        // un plafond de 1).
        if ($contraintes->maxSurveillancesEnseignant !== null
            && ($nbPostes[$candidatId] ?? 0) >= $contraintes->maxSurveillancesEnseignant) {
            return false;
        }

        $dateStr = $epreuve->date->format('Y-m-d');
        $jourSemaine = strtolower($epreuve->date->format('l'));
        $debutStr = substr((string) $epreuve->heure_debut, 0, 5);
        $finStr = substr((string) $epreuve->heure_fin, 0, 5);

        // Unicité sur l'épreuve (Règle 2) : le candidat n'y est pas déjà actif.
        $dejaPresent = $epreuve->surveillants->contains(
            fn ($s) => (int) $s->teacher_id === $candidatId && in_array($s->statut, ['assigne', 'confirme'], true)
        );
        if ($dejaPresent) {
            return false;
        }

        // Conflit interne session : même créneau ce jour-là.
        foreach (($creneaux[$candidatId][$dateStr] ?? []) as [$cDeb, $cFin]) {
            if ($debutStr < $cFin && $cDeb < $finStr) {
                return false;
            }
        }

        // Créneau régulier du MÊME jour uniquement.
        $slots = $slotsGroupes->get($candidatId);
        if ($slots !== null && $slots->contains(fn ($s) => $s->day_of_week === $jourSemaine && $s->chevauche($debutStr, $finStr))) {
            return false;
        }

        // Autres épreuves hors session, même date, horaire chevauchant.
        foreach ($autresEpreuves as $autre) {
            if ($autre->date->format('Y-m-d') !== $dateStr) {
                continue;
            }
            $aDeb = substr((string) $autre->heure_debut, 0, 5);
            $aFin = substr((string) $autre->heure_fin, 0, 5);
            if ($debutStr < $aFin && $aDeb < $finStr && $autre->surveillants->contains('teacher_id', $candidatId)) {
                return false;
            }
        }

        // Cible 3 jours : pas d'ouverture d'un 4e jour.
        $nbJours = count($jours[$candidatId] ?? []);
        if (! isset($jours[$candidatId][$dateStr]) && $nbJours >= $contraintes->maxJours) {
            return false;
        }

        return true;
    }

    /**
     * Seconde passe d'optimisation globale (§11).
     * Rééquilibre les charges et affine la convergence vers les 3 jours par transferts ciblés.
     *
     * @param  array<int, array<string, mixed>>  $propositionsEpreuves
     * @param  array<int, array<string, mixed>>  $etatEnseignants
     * @param  Collection<int, Epreuve>  $epreuvesMap
     * @param  Collection<int, Collection<int, ScheduleSlot>>  $slotsGroupes
     * @param  Collection<int, Epreuve>  $autresEpreuves
     */
    private function optimiserHeuristiqueGlobalement(
        array &$propositionsEpreuves,
        array &$etatEnseignants,
        Collection $epreuvesMap,
        Collection $slotsGroupes,
        Collection $autresEpreuves,
        ?ContraintesSurveillance $contraintes = null
    ): void {
        $contraintes ??= new ContraintesSurveillance;
        $maxIterations = 50;
        $iteration = 0;

        while ($iteration < $maxIterations) {
            $iteration++;

            $maxTId = null;
            $minTId = null;
            $maxCout = -1.0;
            $minCout = 9999.0;

            foreach ($etatEnseignants as $id => $ens) {
                $cout = $this->coutTotal(
                    $ens['charge_ponderee'],
                    $ens['norme_enseignement'] ?? 0.0,
                    $contraintes,
                    ! empty($ens['en_surcharge_enseignement'])
                );
                if ($cout > $maxCout) {
                    $maxCout = $cout;
                    $maxTId = $id;
                }
                if ($cout < $minCout) {
                    $minCout = $cout;
                    $minTId = $id;
                }
            }

            if ($maxTId === null || $minTId === null || ($maxCout - $minCout) <= 1.5) {
                break;
            }

            $transfertEffectue = false;

            foreach ($propositionsEpreuves as &$prop) {
                $epreuveId = $prop['epreuve_id'];
                $epreuve = $epreuvesMap->get($epreuveId);
                if ($epreuve === null) {
                    continue;
                }

                $dateStr = $epreuve->date->format('Y-m-d');
                $debutStr = substr((string) $epreuve->heure_debut, 0, 5);
                $finStr = substr((string) $epreuve->heure_fin, 0, 5);
                $dureeHeures = max(1.0, round(($epreuve->duree_minutes ?: 120) / 60, 2));

                $indexSurv = null;
                $roleSurv = null;
                foreach ($prop['surveillants'] as $sIdx => $sData) {
                    if ($sData['teacher_id'] === $maxTId && empty($sData['existant'])) {
                        $indexSurv = $sIdx;
                        $roleSurv = $sData['role'];
                        break;
                    }
                }

                if ($indexSurv === null) {
                    continue;
                }

                // minTId a-t-il atteint le plafond maximal de surveillances ?
                if ($contraintes->maxSurveillancesEnseignant !== null) {
                    $totalSurvMin = $etatEnseignants[$minTId]['examens_actifs'] + $etatEnseignants[$minTId]['gardes_reserve'];
                    if ($totalSurvMin >= $contraintes->maxSurveillancesEnseignant) {
                        continue;
                    }
                }

                // minTId est-il éligible sans conflit ?
                $dejaSurEpreuve = collect($prop['surveillants'])->contains('teacher_id', $minTId);
                if ($dejaSurEpreuve) {
                    continue;
                }

                $creneauxMin = $etatEnseignants[$minTId]['creneaux_occupes'][$dateStr] ?? [];
                $conflitInterne = false;
                foreach ($creneauxMin as [$cDeb, $cFin]) {
                    if ($debutStr < $cFin && $cDeb < $finStr) {
                        $conflitInterne = true;
                        break;
                    }
                }
                if ($conflitInterne) {
                    continue;
                }

                $slotsMin = $slotsGroupes->get($minTId);
                // Garde jour exact (cf. trouverMeilleurEnseignant) : seul un
                // créneau du même jour de semaine est bloquant.
                $jourSemaineEpreuve = strtolower($epreuve->date->format('l'));
                if ($slotsMin !== null && $slotsMin->contains(fn ($s) => $s->day_of_week === $jourSemaineEpreuve && $s->chevauche($debutStr, $finStr))) {
                    continue;
                }

                $conflitAutre = false;
                foreach ($autresEpreuves as $autreEpreuve) {
                    if ($autreEpreuve->date->format('Y-m-d') !== $dateStr) {
                        continue;
                    }
                    $aDebut = substr((string) $autreEpreuve->heure_debut, 0, 5);
                    $aFin = substr((string) $autreEpreuve->heure_fin, 0, 5);
                    if ($debutStr < $aFin && $aDebut < $finStr) {
                        if ($autreEpreuve->surveillants->contains('teacher_id', $minTId)) {
                            $conflitAutre = true;
                            break;
                        }
                    }
                }
                if ($conflitAutre) {
                    continue;
                }

                $nbJoursMinActuels = count($etatEnseignants[$minTId]['jours_presence']);
                $dejaPresentCeJour = isset($etatEnseignants[$minTId]['jours_presence'][$dateStr]);
                if (! $dejaPresentCeJour && $nbJoursMinActuels >= $contraintes->maxJours) {
                    continue;
                }

                $poids = ($roleSurv === 'reserve') ? $contraintes->poidsGardeReserve : $contraintes->poidsSurveillanceActive;

                if ($roleSurv === 'reserve') {
                    $etatEnseignants[$maxTId]['gardes_reserve']--;
                } else {
                    $etatEnseignants[$maxTId]['examens_actifs']--;
                }
                $etatEnseignants[$maxTId]['charge_ponderee'] -= $poids;
                $etatEnseignants[$maxTId]['heures_totales'] -= $dureeHeures;

                if (isset($etatEnseignants[$maxTId]['creneaux_occupes'][$dateStr])) {
                    foreach ($etatEnseignants[$maxTId]['creneaux_occupes'][$dateStr] as $cIdx => [$cDeb, $cFin]) {
                        if ($cDeb === $debutStr && $cFin === $finStr) {
                            unset($etatEnseignants[$maxTId]['creneaux_occupes'][$dateStr][$cIdx]);
                            break;
                        }
                    }
                    if ($etatEnseignants[$maxTId]['creneaux_occupes'][$dateStr] === []) {
                        unset($etatEnseignants[$maxTId]['jours_presence'][$dateStr]);
                    }
                }

                if ($roleSurv === 'reserve') {
                    $etatEnseignants[$minTId]['gardes_reserve']++;
                } else {
                    $etatEnseignants[$minTId]['examens_actifs']++;
                }
                $etatEnseignants[$minTId]['charge_ponderee'] += $poids;
                $etatEnseignants[$minTId]['heures_totales'] += $dureeHeures;
                $etatEnseignants[$minTId]['jours_presence'][$dateStr] = true;
                $etatEnseignants[$minTId]['creneaux_occupes'][$dateStr][] = [$debutStr, $finStr];

                $prop['surveillants'][$indexSurv] = [
                    'teacher_id' => $minTId,
                    'nom' => $etatEnseignants[$minTId]['nom'],
                    'role' => $roleSurv,
                    'existant' => false,
                ];

                $transfertEffectue = true;
                break;
            }
            unset($prop);

            if (! $transfertEffectue) {
                break;
            }
        }
    }

    /**
     * Recherche heuristique de l'enseignant le plus approprié pour un créneau.
     *
     * @param  array<int, array<string, mixed>>  $etatEnseignants
     * @param  array<int, int>  $utilisesSurCetteEpreuve
     * @param  Collection<int, Collection<int, ScheduleSlot>>  $slotsGroupes
     * @param  Collection<int, Epreuve>  $autresEpreuves
     */
    /**
     * Charge d'enseignement par enseignant (LOOP charge) :
     * Agrégation SQL unique des quotités `volume_heures` des affectations ACTIVES
     * pour l'année universitaire de la session et la faculté (via programme).
     * Distingue CM, TD, TP et Autres. Calcule le volume brut et l'équivalent HETD.
     * Normalisation min-max relative dans [0.0, 1.0].
     *
     * @param  array<int, int>  $teacherIds
     * @return array{
     *     heures: array<int, float>,
     *     hetds: array<int, float>,
     *     details: array<int, array{cm: float, td: float, tp: float, autre: float, total_brut: float, hetd: float}>,
     *     valeurs_retenues: array<int, float>,
     *     norme: array<int, float>,
     *     surcharges: array<int, bool>,
     *     couverture: float,
     *     sans_donnees: int
     * }
     */
    public function chargesEnseignement(
        array $teacherIds,
        ?int $anneeId,
        ?int $faculteId,
        ?ContraintesSurveillance $contraintes = null
    ): array {
        $contraintes ??= new ContraintesSurveillance;
        $details = [];
        $heures = [];
        $hetds = [];
        $valeursRetenues = [];
        $surcharges = [];
        $norme = [];

        foreach ($teacherIds as $id) {
            $id = (int) $id;
            $details[$id] = [
                'cm' => 0.0,
                'td' => 0.0,
                'tp' => 0.0,
                'autre' => 0.0,
                'total_brut' => 0.0,
                'hetd' => 0.0,
            ];
            $heures[$id] = 0.0;
            $hetds[$id] = 0.0;
            $valeursRetenues[$id] = 0.0;
            $surcharges[$id] = false;
            $norme[$id] = 0.0;
        }

        if ($teacherIds !== [] && $anneeId !== null) {
            $lignes = AffectationEnseignement::query()
                ->join('enseignements', 'enseignements.id', '=', 'affectations_enseignement.enseignement_id')
                ->leftJoin('types_enseignement', 'types_enseignement.id', '=', 'enseignements.type_id')
                ->leftJoin('programmes', 'programmes.id', '=', 'enseignements.programme_id')
                ->whereIn('affectations_enseignement.teacher_id', $teacherIds)
                ->where('affectations_enseignement.statut', 'active')
                ->where('enseignements.annee_universitaire_id', $anneeId)
                ->when($faculteId !== null, fn ($q) => $q->where('programmes.faculte_id', $faculteId))
                ->select([
                    'affectations_enseignement.teacher_id',
                    DB::raw("SUM(CASE WHEN UPPER(types_enseignement.code) = 'CM' THEN affectations_enseignement.volume_heures ELSE 0 END) as heures_cm"),
                    DB::raw("SUM(CASE WHEN UPPER(types_enseignement.code) = 'TD' THEN affectations_enseignement.volume_heures ELSE 0 END) as heures_td"),
                    DB::raw("SUM(CASE WHEN UPPER(types_enseignement.code) = 'TP' THEN affectations_enseignement.volume_heures ELSE 0 END) as heures_tp"),
                    DB::raw("SUM(CASE WHEN UPPER(types_enseignement.code) NOT IN ('CM', 'TD', 'TP') OR types_enseignement.code IS NULL THEN affectations_enseignement.volume_heures ELSE 0 END) as heures_autre"),
                    DB::raw('SUM(affectations_enseignement.volume_heures) as heures_total'),
                ])
                ->groupBy('affectations_enseignement.teacher_id')
                ->get();

            foreach ($lignes as $ligne) {
                $id = (int) $ligne->teacher_id;
                $cm = round((float) $ligne->heures_cm, 1);
                $td = round((float) $ligne->heures_td, 1);
                $tp = round((float) $ligne->heures_tp, 1);
                $autre = round((float) $ligne->heures_autre, 1);
                $totalBrut = round((float) $ligne->heures_total, 1);
                // Équivalence HETD standard académique : CM = 1.5, TD = 1.0, TP = 0.75, Autre = 1.0
                $hetd = round(($cm * 1.5) + ($td * 1.0) + ($tp * 0.75) + ($autre * 1.0), 1);

                $details[$id] = [
                    'cm' => $cm,
                    'td' => $td,
                    'tp' => $tp,
                    'autre' => $autre,
                    'total_brut' => $totalBrut,
                    'hetd' => $hetd,
                ];
                $heures[$id] = $totalBrut;
                $hetds[$id] = $hetd;
            }
        }

        $seuilSurcharge = $contraintes->seuilSurchargeEnseignement;
        $modeCalcul = $contraintes->modeCalculChargeEnseignement;

        foreach ($teacherIds as $id) {
            $id = (int) $id;
            $valeur = $modeCalcul === 'hetd' ? $hetds[$id] : $heures[$id];
            $valeursRetenues[$id] = $valeur;
            if ($seuilSurcharge !== null && $valeur > $seuilSurcharge) {
                $surcharges[$id] = true;
            }
        }

        $min = $valeursRetenues === [] ? 0.0 : min($valeursRetenues);
        $max = $valeursRetenues === [] ? 0.0 : max($valeursRetenues);

        foreach ($valeursRetenues as $id => $val) {
            // Normalisation min-max relative dans [0.0, 1.0]
            $norme[$id] = ($max - $min) > 0.01 ? round(($val - $min) / ($max - $min), 4) : 0.0;
        }

        $avecDonnees = count(array_filter($heures, fn ($h) => $h > 0));
        $totalMobilises = count($teacherIds);

        return [
            'heures' => $heures,
            'hetds' => $hetds,
            'details' => $details,
            'valeurs_retenues' => $valeursRetenues,
            'norme' => $norme,
            'surcharges' => $surcharges,
            'couverture' => $totalMobilises === 0 ? 1.0 : round($avecDonnees / $totalMobilises, 3),
            'sans_donnees' => $totalMobilises - $avecDonnees,
        ];
    }

    /**
     * Coût total de sélection : charge de surveillance + terme
     * d'enseignement normalisé (nul si critère inactif).
     * Si un enseignant est en surcharge d'enseignement, un malus additionnel
     * est appliqué.
     */
    private function coutTotal(
        float $chargeSurveillance,
        float $normeEnseignement,
        ContraintesSurveillance $contraintes,
        bool $enSurcharge = false
    ): float {
        $cout = $chargeSurveillance + ($normeEnseignement * $contraintes->poidsChargeEnseignementEffectif());
        if ($enSurcharge && $contraintes->actifChargeEnseignement) {
            $cout += ($contraintes->poidsChargeEnseignementEffectif() * 1.5);
        }

        return $cout;
    }

    private function trouverMeilleurEnseignant(
        array $etatEnseignants,
        Epreuve $epreuve,
        string $dateStr,
        string $debutStr,
        string $finStr,
        float $dureeHeures,
        bool $pourGarde,
        array $utilisesSurCetteEpreuve,
        Collection $slotsGroupes,
        Collection $autresEpreuves,
        ?ContraintesSurveillance $contraintes = null,
        array $chargeEnsNorme = []
    ): ?array {
        $contraintes ??= new ContraintesSurveillance;
        $candidatsEligibles = [];
        $jourSemaine = strtolower($epreuve->date->format('l'));

        foreach ($etatEnseignants as $id => $ens) {
            // 1. Déjà affecté sur cette même épreuve ?
            if (in_array($id, $utilisesSurCetteEpreuve, true)) {
                continue;
            }

            // 2. Conflit interne sur la simulation (même créneau horaire ce jour-là)
            $creneauxJour = $ens['creneaux_occupes'][$dateStr] ?? [];
            $enConflitInterne = false;
            foreach ($creneauxJour as [$cDebut, $cFin]) {
                if ($debutStr < $cFin && $cDebut < $finStr) {
                    $enConflitInterne = true;
                    break;
                }
            }
            if ($enConflitInterne) {
                continue;
            }

            // 3. Conflit externe avec cours réguliers (ScheduleSlot du MÊME jour uniquement)
            $slots = $slotsGroupes->get($id);
            if ($slots !== null) {
                $enConflitCours = $slots->contains(fn ($s) => $s->day_of_week === $jourSemaine && $s->chevauche($debutStr, $finStr));
                if ($enConflitCours) {
                    continue;
                }
            }

            // 4. Conflit externe avec autres épreuves hors session
            $enConflitAutreEpreuve = false;
            foreach ($autresEpreuves as $autreEpreuve) {
                if ($autreEpreuve->date->format('Y-m-d') !== $dateStr) {
                    continue;
                }
                $aDebut = substr((string) $autreEpreuve->heure_debut, 0, 5);
                $aFin = substr((string) $autreEpreuve->heure_fin, 0, 5);

                if ($debutStr < $aFin && $aDebut < $finStr) {
                    if ($autreEpreuve->surveillants->contains('teacher_id', $id)) {
                        $enConflitAutreEpreuve = true;
                        break;
                    }
                }
            }
            if ($enConflitAutreEpreuve) {
                continue;
            }

            // 4b. Limite de jours en mode STRICT : ouvrir un nouveau jour
            // au-delà du maximum configuré est exclu (contrainte dure
            // explicite de l'administrateur, jamais un score).
            $nbJoursCandidat = count($ens['jours_presence']);
            if ($contraintes->limiteJoursStricte()
                && ! isset($ens['jours_presence'][$dateStr])
                && $nbJoursCandidat >= $contraintes->maxJours) {
                continue;
            }

            // 4c. Plafond maximal de surveillances par enseignant (si configuré)
            if ($contraintes->maxSurveillancesEnseignant !== null) {
                $totalSurv = $ens['examens_actifs'] + $ens['gardes_reserve'];
                if ($totalSurv >= $contraintes->maxSurveillancesEnseignant) {
                    continue;
                }
            }

            // 5. Calcul de score d'affectation (plus le score est bas, plus il est prioritaire, §9/§12)
            // Garde jour exact : un cours régulier n'est bloquant que s'il
            // tombe le MÊME jour de semaine que l'épreuve (les créneaux
            // sont hebdomadaires récurrents, pas datés).
            $chargeActuelle = $ens['charge_ponderee'];
            $chargeJour = count($ens['creneaux_occupes'][$dateStr] ?? []);
            $nbJoursActuels = count($ens['jours_presence']);
            $dejaPresentCeJour = isset($ens['jours_presence'][$dateStr]);

            $penaliteJour = 0.0;
            if ($dejaPresentCeJour) {
                // Optimal : mutualisation de la venue sur le campus
                $penaliteJour = 0.0;
            } else {
                if ($nbJoursActuels < $contraintes->maxJours) {
                    // Contribue à atteindre la cible de jours
                    $penaliteJour = $contraintes->penaliteNouveauJourEffectif();
                } else {
                    // Dépassement de la cible : fortement pénalisé
                    $penaliteJour = $contraintes->penaliteDepassementEffectif();
                }

                // Pénalité de dispersion temporelle (§6/§12 : favoriser la contiguïté J1-J2-J3 ou J4-J5-J6)
                if ($nbJoursActuels > 0) {
                    $tsCible = strtotime($dateStr);
                    $distMinJours = 999;
                    foreach (array_keys($ens['jours_presence']) as $dExistante) {
                        $diff = abs($tsCible - strtotime($dExistante)) / 86400;
                        if ($diff < $distMinJours) {
                            $distMinJours = $diff;
                        }
                    }
                    if ($distMinJours > 2) {
                        $penaliteJour += $contraintes->penaliteDispersionEffectif();
                    }
                }
            }

            $penaliteGarde = 0.0;
            if ($pourGarde) {
                $penaliteGarde = $ens['gardes_reserve'] * $contraintes->poidsGardesEffectif();
            }

            // Score composite pondéré (§9/§12) + terme d'enseignement
            // normalisé (nul si critère inactif ; jamais d'addition brute
            // heures/postes : unités incompatibles).
            $termeEnseignement = ($chargeEnsNorme[$id] ?? 0.0) * $contraintes->poidsChargeEnseignementEffectif();
            if (! empty($ens['en_surcharge_enseignement']) && $contraintes->actifChargeEnseignement) {
                $termeEnseignement += ($contraintes->poidsChargeEnseignementEffectif() * 1.5);
            }

            $score = ($chargeActuelle * $contraintes->poidsChargeTotaleEffectif())
                + ($chargeJour * $contraintes->poidsChargeJourEffectif())
                + $penaliteJour
                + $penaliteGarde
                + $termeEnseignement;

            $candidatsEligibles[] = [
                'id' => $id,
                'score' => $score,
                'charge' => $chargeActuelle,
                'jours' => $nbJoursActuels,
            ];
        }

        if ($candidatsEligibles === []) {
            return null;
        }

        // Tri déterministe : score ASC, charge ASC, charge enseignement retenue ASC, id ASC
        usort($candidatsEligibles, function ($a, $b) use ($etatEnseignants, $contraintes) {
            if (abs($a['score'] - $b['score']) > 0.01) {
                return $a['score'] <=> $b['score'];
            }
            if (abs($a['charge'] - $b['charge']) > 0.01) {
                return $a['charge'] <=> $b['charge'];
            }
            if ($contraintes->actifChargeEnseignement) {
                $ceA = $etatEnseignants[$a['id']]['charge_enseignement_retenue'] ?? 0.0;
                $ceB = $etatEnseignants[$b['id']]['charge_enseignement_retenue'] ?? 0.0;
                if (abs($ceA - $ceB) > 0.01) {
                    return $ceA <=> $ceB;
                }
            }

            return $a['id'] <=> $b['id'];
        });

        return $candidatsEligibles[0];
    }

    /**
     * Ventilation des postes proposés par salle (point 2) : chaque salle
     * reçoit ses `besoin` surveillants identifiés ; le principal, créé en
     * premier, est placé dans la salle au plus fort besoin. Déterministe :
     * remplissage par (besoin desc, salle_id asc). Les existants déjà
     * rattachés à une salle de l'épreuve sont décomptés, les autres comptent
     * sur la salle principale. La garde reste rattachée à la salle
     * principale (comportement conservé).
     *
     * @param  array<int, array<string, mixed>>  $propositions
     * @param  array{besoins_salles?: array<int, array{salle_id: int, code: string, candidats: int, besoin: int}>}  $besoin
     */
    private function ventilerPropositionsParSalle(array &$propositions, array $besoin, ?int $sallePrincipaleId, ?string $codePrincipal): void
    {
        $salles = collect($besoin['besoins_salles'] ?? [])
            ->sort(fn (array $a, array $b) => [$b['besoin'], $a['salle_id']] <=> [$a['besoin'], $b['salle_id']])
            ->values();

        if ($salles->isEmpty()) {
            foreach ($propositions as &$p) {
                if (empty($p['existant'])) {
                    $p['salle_id'] = $sallePrincipaleId;
                    $p['salle_code'] = $codePrincipal;
                }
            }
            unset($p);

            return;
        }

        $restants = [];
        foreach ($salles as $s) {
            $restants[$s['salle_id']] = $s['besoin'];
        }

        foreach ($propositions as $p) {
            if (empty($p['existant']) || ($p['role'] ?? null) === 'reserve') {
                continue;
            }
            $salleExist = $p['salle_id'] ?? null;
            if ($salleExist !== null && isset($restants[$salleExist]) && $restants[$salleExist] > 0) {
                $restants[$salleExist]--;
            } elseif ($sallePrincipaleId !== null && isset($restants[$sallePrincipaleId]) && $restants[$sallePrincipaleId] > 0) {
                $restants[$sallePrincipaleId]--;
            }
        }

        foreach ($propositions as &$p) {
            if (! empty($p['existant']) || ($p['role'] ?? null) === 'reserve') {
                continue;
            }
            $choisie = null;
            foreach ($salles as $s) {
                if (($restants[$s['salle_id']] ?? 0) > 0) {
                    $choisie = $s;
                    break;
                }
            }
            $choisie ??= $salles->first();
            $p['salle_id'] = $choisie['salle_id'];
            $p['salle_code'] = $choisie['code'];
            $restants[$choisie['salle_id']]--;
        }
        unset($p);
    }

    /**
     * Diagnostic de dimensionnement par salle joint aux anomalies de carence
     * (§5) : salle concernée, candidats, requis, éligibles, règles
     * configurées — sans PII (codes de salles et nombres uniquement).
     *
     * @param  array{besoin_surveillants_actifs: int, besoins_salles?: array<int, array{salle_id: int, code: string, candidats: int, besoin: int, minimum_applique: int, ratio_applique: int, source_regle: string}>}  $besoin
     */
    private function diagnosticBesoinsSalles(array $besoin, ContraintesSurveillance $contraintes, int $nbEligibles): string
    {
        $details = [];
        foreach (($besoin['besoins_salles'] ?? []) as $salle) {
            $details[] = "{$salle['code']} ({$salle['candidats']} candidats → {$salle['besoin']} requis, min {$salle['minimum_applique']}, ratio {$salle['ratio_applique']}, règle {$salle['source_regle']})";
        }

        $resume = $details !== [] ? implode(' ; ', $details) : 'sans ventilation par salle';
        $regles = "min global {$contraintes->minSurveillants}, min/salle {$contraintes->minParSalle}, ratio {$contraintes->ratioEtudiants}";
        if ($contraintes->seuilGrandeSalle !== null && $contraintes->minSurveillantsGrandeSalle !== null) {
            $regles .= ", seuil grande salle {$contraintes->seuilGrandeSalle} (min {$contraintes->minSurveillantsGrandeSalle})";
        }

        return "Besoins par salle : {$resume}. Requis actifs : {$besoin['besoin_surveillants_actifs']} pour {$nbEligibles} enseignant(s) éligible(s). Règles : {$regles}.";
    }

    /**
     * Analyse structurée des causes d'indisponibilité pour un poste (Phase 12 - Diagnostic).
     *
     * @param  array<int, array<string, mixed>>  $etatEnseignants
     * @param  array<int, int>  $utilisesSurCetteEpreuve
     * @param  Collection<int, Collection<int, ScheduleSlot>>  $slotsGroupes
     * @param  Collection<int, Epreuve>  $autresEpreuves
     */
    private function diagnostiquerCarence(
        array $etatEnseignants,
        Epreuve $epreuve,
        string $dateStr,
        string $debutStr,
        string $finStr,
        array $utilisesSurCetteEpreuve,
        Collection $slotsGroupes,
        Collection $autresEpreuves,
        ContraintesSurveillance $contraintes
    ): string {
        $nbTotal = count($etatEnseignants);
        $dejaAffectesEpreuve = 0;
        $conflitCreneauInterne = 0;
        $conflitCoursRegulier = 0;
        $conflitAutreExamen = 0;
        $plafondJoursStrict = 0;
        $plafondSurveillancesMax = 0;
        $jourSemaine = strtolower($epreuve->date->format('l'));

        foreach ($etatEnseignants as $id => $ens) {
            if (in_array($id, $utilisesSurCetteEpreuve, true)) {
                $dejaAffectesEpreuve++;

                continue;
            }

            $creneauxJour = $ens['creneaux_occupes'][$dateStr] ?? [];
            $interne = false;
            foreach ($creneauxJour as [$cDeb, $cFin]) {
                if ($debutStr < $cFin && $cDeb < $finStr) {
                    $interne = true;
                    break;
                }
            }
            if ($interne) {
                $conflitCreneauInterne++;

                continue;
            }

            $slots = $slotsGroupes->get($id);
            if ($slots !== null && $slots->contains(fn ($s) => $s->day_of_week === $jourSemaine && $s->chevauche($debutStr, $finStr))) {
                $conflitCoursRegulier++;

                continue;
            }

            $autre = false;
            foreach ($autresEpreuves as $autreEpreuve) {
                if ($autreEpreuve->date->format('Y-m-d') !== $dateStr) {
                    continue;
                }
                $aDeb = substr((string) $autreEpreuve->heure_debut, 0, 5);
                $aFin = substr((string) $autreEpreuve->heure_fin, 0, 5);
                if ($debutStr < $aFin && $aDeb < $finStr && $autreEpreuve->surveillants->contains('teacher_id', $id)) {
                    $autre = true;
                    break;
                }
            }
            if ($autre) {
                $conflitAutreExamen++;

                continue;
            }

            $nbJours = count($ens['jours_presence']);
            if ($contraintes->limiteJoursStricte() && ! isset($ens['jours_presence'][$dateStr]) && $nbJours >= $contraintes->maxJours) {
                $plafondJoursStrict++;

                continue;
            }

            if ($contraintes->maxSurveillancesEnseignant !== null) {
                $totalSurv = $ens['examens_actifs'] + $ens['gardes_reserve'];
                if ($totalSurv >= $contraintes->maxSurveillancesEnseignant) {
                    $plafondSurveillancesMax++;

                    continue;
                }
            }
        }

        $details = [];
        if ($conflitCreneauInterne > 0) {
            $details[] = "{$conflitCreneauInterne} déjà sur un créneau concurrent ce jour-là";
        }
        if ($conflitCoursRegulier > 0) {
            $details[] = "{$conflitCoursRegulier} en conflit de cours (ScheduleSlot)";
        }
        if ($conflitAutreExamen > 0) {
            $details[] = "{$conflitAutreExamen} affectés sur une autre épreuve";
        }
        if ($dejaAffectesEpreuve > 0) {
            $details[] = "{$dejaAffectesEpreuve} déjà affectés sur cette épreuve";
        }
        if ($plafondJoursStrict > 0) {
            $details[] = "{$plafondJoursStrict} au plafond strict de {$contraintes->maxJours} jours";
        }
        if ($plafondSurveillancesMax > 0) {
            $details[] = "{$plafondSurveillancesMax} au plafond maximal de {$contraintes->maxSurveillancesEnseignant} surveillances";
        }

        return $details !== [] ? implode(', ', $details) : "aucun enseignant disponible sur {$nbTotal} mobilisables";
    }
}
