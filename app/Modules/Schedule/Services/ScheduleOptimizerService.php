<?php

namespace App\Modules\Schedule\Services;

use App\Models\Matiere;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleAvailability;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Models\TimeSlot;
use App\Modules\Schedule\Models\TimetableScenario;

/**
 * PHASE 5 (Smart Timetables) — Service d'optimisation avancée des préférences,
 * d'exploration de scénarios comparatifs et de diagnostic assisté d'infaisabilité.
 */
class ScheduleOptimizerService
{
    public function __construct(
        private readonly ScheduleConflictService $conflictService,
        private readonly ScheduleConstraintChecker $checker,
        private readonly ScheduleConstraintRegistry $registry,
        private readonly ScheduleCalendarService $calendarService,
    ) {}

    /**
     * Produit un scénario optimisé par recherche locale (Hill-Climbing)
     * visant à maximiser le score de qualité et minimiser les trous.
     *
     * @param  array<string, mixed>  $options
     */
    public function optimiser(Schedule $schedule, array $options = []): TimetableScenario
    {
        $nom = $options['nom'] ?? 'Scénario Optimisé #'.(TimetableScenario::where('schedule_id', $schedule->id)->count() + 1);
        $code = $options['code'] ?? 'SCEN_'.strtoupper(substr(uniqid(), -6));
        $maxIterations = (int) ($options['iterations'] ?? 50);
        $auteurId = $options['auteur_id'] ?? null;

        $slotsInitiaux = $schedule->slots()->where('status', '!=', 'cancelled')->get();
        if ($slotsInitiaux->isEmpty()) {
            return TimetableScenario::create([
                'schedule_id' => $schedule->id,
                'code' => $code,
                'nom' => $nom,
                'statut' => TimetableScenario::STATUT_EVALUE,
                'score_qualite' => 100,
                'nb_conflits_durs' => 0,
                'creneaux_json' => [],
                'metriques_json' => [],
                'parametres_optimisation' => $options,
                'auteur_id' => $auteurId,
            ]);
        }

        // Évaluation de l'état de départ
        $auditInitial = $this->checker->auditerPlanning($schedule);
        $meilleurScore = $auditInitial->scoreQualite();
        $meilleureConfiguration = $slotsInitiaux->map(fn ($s) => $s->toArray())->all();

        // Récupération des plages candidates et des salles compatibles
        $faculteId = $schedule->groupe?->faculte_id;
        $plages = TimeSlot::query()->actif()->cours()->pourFaculte($faculteId)->get();
        $salles = Room::where('actif', true)
            ->where(fn ($q) => $q->whereNull('faculte_id')->orWhere('faculte_id', $faculteId))
            ->get();

        $configCourante = $meilleureConfiguration;

        // Boucle de recherche locale
        for ($iter = 0; $iter < $maxIterations; $iter++) {
            $ameliore = false;

            // Essayer de déplacer chaque créneau vers une plage libre réduisant les trous
            foreach ($configCourante as $idx => $slotData) {
                foreach ($plages as $plage) {
                    $nouveauJour = $plage->day_of_week;
                    $nouveauDebut = substr((string) $plage->start_time, 0, 8);
                    $nouveauFin = substr((string) $plage->end_time, 0, 8);

                    if ($slotData['day_of_week'] === $nouveauJour && $slotData['start_time'] === $nouveauDebut) {
                        continue;
                    }

                    // Test de validité dure sur le déplacement
                    $conflits = $this->conflictService->verifier(
                        $schedule->id,
                        $nouveauJour,
                        $nouveauDebut,
                        $nouveauFin,
                        $slotData['teacher_id'],
                        $slotData['room_id'],
                        $slotData['id'] ?? null,
                        null,
                        $schedule,
                        $slotData['semaine_parite'] ?? 'toutes',
                        $slotData['semaines_specifiques'] ?? null,
                        $slotData['sous_groupe_id'] ?? null,
                        $slotData['equipements_requis'] ?? null,
                        $slotData['co_enseignants_ids'] ?? null,
                        $slotData['matiere_id']
                    );

                    if (! empty($conflits)) {
                        continue;
                    }

                    // Configuration candidate
                    $candidateConfig = $configCourante;
                    $candidateConfig[$idx]['day_of_week'] = $nouveauJour;
                    $candidateConfig[$idx]['start_time'] = $nouveauDebut;
                    $candidateConfig[$idx]['end_time'] = $nouveauFin;

                    // Évaluation de la qualité de la configuration candidate
                    $scoreCandidat = $this->evaluerScoreConfiguration($candidateConfig, $schedule);

                    if ($scoreCandidat > $meilleurScore) {
                        $meilleurScore = $scoreCandidat;
                        $meilleureConfiguration = $candidateConfig;
                        $configCourante = $candidateConfig;
                        $ameliore = true;
                        break 2; // Accepter la première amélioration stricte (First-Choice Hill Climbing)
                    }
                }
            }

            if (! $ameliore) {
                break; // Optimum local atteint
            }
        }

        // Audit final de la configuration retenue
        $auditFinal = $this->evaluerAuditConfiguration($meilleureConfiguration, $schedule);

        return TimetableScenario::create([
            'schedule_id' => $schedule->id,
            'code' => $code,
            'nom' => $nom,
            'statut' => TimetableScenario::STATUT_EVALUE,
            'score_qualite' => $auditFinal->scoreQualite(),
            'nb_conflits_durs' => $auditFinal->nombreViolationsDures(),
            'creneaux_json' => $meilleureConfiguration,
            'metriques_json' => $auditFinal->metriques(),
            'parametres_optimisation' => array_merge($options, [
                'iterations_effectuees' => $iter,
                'score_initial' => $auditInitial->scoreQualite(),
                'gain_score' => $auditFinal->scoreQualite() - $auditInitial->scoreQualite(),
            ]),
            'auteur_id' => $auteurId,
        ]);
    }

    /**
     * Analyse et diagnostique les causes précises d'échec de placement (goulots d'étranglement).
     *
     * @param  iterable<SeanceDemande>  $seancesNonPlacees
     * @return array<string, array<string, mixed>>
     */
    public function diagnostiquerInfaisabilite(Schedule $schedule, iterable $seancesNonPlacees): array
    {
        $diagnostic = [];
        $faculteId = $schedule->groupe?->faculte_id;
        $effectif = $schedule->groupe?->effectifActuel() ?: ($schedule->groupe?->capacite ?? 0);

        foreach ($seancesNonPlacees as $seance) {
            $raisons = [];
            $recommandations = [];

            // 1. Diagnostic de capacité et d'équipements des salles
            $sallesCompatibles = Room::where('actif', true)
                ->where(fn ($q) => $q->whereNull('faculte_id')->orWhere('faculte_id', $faculteId))
                ->when($effectif > 0, fn ($q) => $q->where('capacite', '>=', $effectif))
                ->get();

            if ($sallesCompatibles->isEmpty()) {
                $raisons[] = "Aucune salle disponible n'a une capacité suffisante pour l'effectif de {$effectif} étudiants.";
                $recommandations[] = 'Scinder le groupe en sous-groupes ou affecter un amphithéâtre plus grand.';
            }

            if (! empty($seance->equipementsRequis)) {
                $sallesEquipees = $sallesCompatibles->filter(function (Room $r) use ($seance) {
                    $fixes = $r->equipements_fixes ?? [];
                    foreach ($seance->equipementsRequis as $eq) {
                        if (! in_array($eq, $fixes, true)) {
                            return false;
                        }
                    }

                    return true;
                });

                if ($sallesEquipees->isEmpty()) {
                    $equipStr = implode(', ', $seance->equipementsRequis);
                    $raisons[] = "Aucune salle ne dispose de la totalité des équipements requis : [{$equipStr}].";
                    $recommandations[] = 'Déclarer les équipements dans les salles du campus ou assouplir les prérequis matériels.';
                }
            }

            // 2. Diagnostic de disponibilité de l'enseignant
            if ($seance->teacherId !== null) {
                $enseignant = Teacher::find($seance->teacherId);
                $nomEns = $enseignant ? "{$enseignant->prenom} {$enseignant->nom}" : "Enseignant #{$seance->teacherId}";

                $indisponibilites = ScheduleAvailability::query()
                    ->where('teacher_id', $seance->teacherId)
                    ->indisponibles()
                    ->get();

                if ($indisponibilites->count() >= 3) {
                    $raisons[] = "L'enseignant {$nomEns} a déclaré {$indisponibilites->count()} plages d'indisponibilité bloquantes.";
                    $recommandations[] = "Attribuer un co-enseignant ou négocier les créneaux d'indisponibilité.";
                }

                if ($enseignant && ! $enseignant->estQualifiePour($seance->matiereId)) {
                    $raisons[] = "L'enseignant {$nomEns} ne possède pas la qualification requise pour cette matière.";
                    $recommandations[] = "Mettre à jour les qualifications de l'enseignant ou désigner un titulaire habilité.";
                }
            } else {
                $enseignantsQualifies = Teacher::where('actif', true)
                    ->get()
                    ->filter(fn (Teacher $t) => $t->estQualifiePour($seance->matiereId));

                if ($enseignantsQualifies->isEmpty()) {
                    $raisons[] = "Aucun enseignant actif dans l'établissement ne possède la qualification pour cette matière.";
                    $recommandations[] = "Affecter les qualifications d'enseignement requises dans le référentiel des enseignants.";
                }
            }

            // 3. Diagnostic de grille horaire
            $nbCreneauxGrille = TimeSlot::query()->actif()->cours()->pourFaculte($faculteId)->count();
            if ($nbCreneauxGrille === 0) {
                $raisons[] = 'La grille horaire de la faculté ne contient aucun créneau de cours actif.';
                $recommandations[] = "Définir la grille horaire standard dans les paramètres d'emplois du temps.";
            }

            $diagnostic[$seance->id] = [
                'seance_id' => $seance->id,
                'titre' => $seance->titre ?: $seance->id,
                'matiere_id' => $seance->matiereId,
                'bloquants' => empty($raisons) ? ['Saturation globale des créneaux horaires disponibles sans conflit'] : $raisons,
                'recommandations' => empty($recommandations) ? ['Étendre la plage des jours ouvrés autorisés ou augmenter le budget de recherche'] : $recommandations,
            ];
        }

        return $diagnostic;
    }

    /**
     * Compare une liste de scénarios et fournit une matrice synthétique de décision.
     *
     * @param  array<int, TimetableScenario>  $scenarios
     * @return array<string, mixed>
     */
    public function comparerScenarios(array $scenarios): array
    {
        $tableau = [];
        $meilleurScenario = null;
        $scoreMax = -1;

        foreach ($scenarios as $scenario) {
            $valide = $scenario->estValide();
            $score = $scenario->score_qualite ?? 0;
            $metriques = $scenario->metriques_json ?? [];

            $tableau[] = [
                'id' => $scenario->id,
                'code' => $scenario->code,
                'nom' => $scenario->nom,
                'statut' => $scenario->statut,
                'est_valide' => $valide,
                'score_qualite' => $score,
                'total_creneaux' => count($scenario->creneaux_json ?? []),
                'trous_etudiants_minutes' => $metriques['total_minutes_trous_etudiants'] ?? 0,
                'trous_enseignants_minutes' => $metriques['total_minutes_trous_enseignants'] ?? 0,
                'taux_occupation_salles' => $metriques['taux_occupation_moyen_salles'] ?? 0,
            ];

            if ($valide && $score > $scoreMax) {
                $scoreMax = $score;
                $meilleurScenario = $scenario;
            }
        }

        return [
            'scenarios' => $tableau,
            'recommandation' => [
                'scenario_id' => $meilleurScenario?->id,
                'nom' => $meilleurScenario?->nom,
                'score_qualite' => $scoreMax >= 0 ? $scoreMax : null,
                'motif' => $meilleurScenario !== null
                    ? "Scénario {$meilleurScenario->nom} optimal : 0 conflit dur et score maximal de {$scoreMax}/100."
                    : 'Aucun scénario 100% réalisable identifié.',
            ],
        ];
    }

    /**
     * Évalue le score qualité global d'une configuration de créneaux en mémoire.
     *
     * @param  array<int, array<string, mixed>>  $configuration
     */
    private function evaluerScoreConfiguration(array $configuration, Schedule $schedule): int
    {
        $audit = $this->evaluerAuditConfiguration($configuration, $schedule);

        return $audit->scoreQualite();
    }

    /**
     * Produit un ScheduleAuditReport complet sur une configuration en mémoire.
     *
     * @param  array<int, array<string, mixed>>  $configuration
     */
    private function evaluerAuditConfiguration(array $configuration, Schedule $schedule): ScheduleAuditReport
    {
        $slotsVirtuels = collect();

        foreach ($configuration as $data) {
            $slot = new ScheduleSlot([
                'schedule_id' => $schedule->id,
                'matiere_id' => $data['matiere_id'],
                'teacher_id' => $data['teacher_id'] ?? null,
                'room_id' => $data['room_id'] ?? null,
                'day_of_week' => $data['day_of_week'],
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'status' => 'scheduled',
                'type' => $data['type'] ?? 'course',
                'sous_groupe_id' => $data['sous_groupe_id'] ?? null,
                'semaine_parite' => $data['semaine_parite'] ?? 'toutes',
            ]);

            $slot->setRelation('matiere', Matiere::find($data['matiere_id']));
            $slot->setRelation('room', Room::with('batimentRelation')->find($data['room_id'] ?? null));
            $slot->setRelation('teacher', Teacher::find($data['teacher_id'] ?? null));
            $slot->setRelation('coEnseignants', collect());
            $slot->setRelation('schedule', $schedule);

            $slotsVirtuels->push($slot);
        }

        return $this->checker->auditerCreneaux($slotsVirtuels, $schedule->groupe?->faculte_id, $schedule);
    }
}
