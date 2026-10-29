<?php

namespace App\Modules\Evaluation\Services;

use App\Models\AnneeUniversitaire;
use App\Models\User;
use App\Modules\Evaluation\Models\DocumentExamenGenere;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Services\ExamScheduleEngineService;
use Illuminate\Support\Collection;

/**
 * Service d'orchestration Enterprise pour le Tableau de bord des examens (Menu 1).
 *
 * Consolide les indicateurs, les scores de préparation, la matrice des conflits
 * (Salles, Candidats, Surveillants, Planning) et la gouvernance à 4 yeux sans
 * dupliquer les règles métier des 12 menus fonctionnels.
 *
 * Règle d'or : strictement en LECTURE SEULE. Aucune altération des données réelles.
 */
class ExamDashboardOrchestratorService
{
    public function __construct(
        private PreparationExamenService $preparationService,
        private ExamScheduleEngineService $scheduleEngine,
        private ConflitEpreuveService $conflitService,
    ) {}

    /**
     * Construit le modèle de données complet et consolidé du Command Center.
     *
     * @return array<string, mixed>
     */
    public function obtenirTableauDeBordEnterprise(?int $anneeId, ?int $faculteId, ?User $user = null): array
    {
        $maintenant = now();

        // 1. Années universitaires et année active
        $annees = AnneeUniversitaire::orderByDesc('date_debut')->get();
        $anneeSelectionnee = $anneeId !== null
            ? $annees->firstWhere('id', $anneeId)
            : $annees->firstWhere('active', true) ?? $annees->first();

        $anneeIdFiltre = $anneeSelectionnee?->id;

        // 2. Sessions de la période avec relations nécessaires
        $sessionsQuery = SessionExamen::query()
            ->when($anneeIdFiltre !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeIdFiltre))
            ->with(['anneeUniversitaire', 'origine'])
            ->withCount(['examens', 'epreuves'])
            ->orderByDesc('date_debut');

        $sessions = $sessionsQuery->get();

        // 3. Épreuves du périmètre
        $epreuves = Epreuve::query()
            ->when($anneeIdFiltre !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeIdFiltre))
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->with(['matiere', 'groupe', 'salle', 'surveillants.enseignant', 'session'])
            ->withCount('candidats')
            ->get();

        // 4. Salles du périmètre
        $sallesQuery = Room::query()
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId)->orWhereNull('faculte_id'))
            ->with('batimentRelation');
        $salles = $sallesQuery->get();

        // 5. Calcul des indicateurs consolidés (sans incohérence de périmètre)
        $kpis = $this->calculerKpisConsolides($sessions, $epreuves, $salles, $anneeIdFiltre, $faculteId);

        // 6. Détection consolidée des conflits (Familles A, B, C, D)
        $matriceConflits = $this->detecterConflitsConsolides($epreuves, $salles, $sessions);

        // 7. Calcul des scores de préparation des sessions
        $scoresSessions = $this->calculerScoresSessions($sessions, $epreuves, $matriceConflits);

        // 8. Analyse des capacités et saturation des salles
        $diagnosticSalles = $this->analyserCapacitesSalles($salles, $epreuves);

        // 9. Statut du workflow de gouvernance à 4 yeux
        $gouvernance = $this->evaluerGouvernanceWorkflow($epreuves, $sessions, $user);

        // 10. Fiabilité et fraîcheur des données
        $fiabilite = $this->evaluerFiabiliteDonnees($sessions, $epreuves, $salles);

        // 11. Grille d'intégration des 12 menus avec liens contextuels
        $grilleMenus = $this->construireGrille12Menus($anneeIdFiltre, $faculteId, $kpis, $matriceConflits);

        return [
            'horodatage' => $maintenant,
            'annee_selectionnee' => $anneeSelectionnee,
            'annees' => $annees,
            'annee_id' => $anneeIdFiltre,
            'faculte_id' => $faculteId,
            'fiabilite' => $fiabilite,
            'kpis' => $kpis,
            'matrice_conflits' => $matriceConflits,
            'scores_sessions' => $scoresSessions,
            'diagnostic_salles' => $diagnosticSalles,
            'gouvernance' => $gouvernance,
            'grille_menus' => $grilleMenus,
        ];
    }

    /**
     * Calcule des KPI strictement harmonisés sur l'année et la faculté.
     *
     * @param  Collection<int, SessionExamen>  $sessions
     * @param  Collection<int, Epreuve>  $epreuves
     * @param  Collection<int, Room>  $salles
     * @return array<string, mixed>
     */
    public function calculerKpisConsolides(
        Collection $sessions,
        Collection $epreuves,
        Collection $salles,
        ?int $anneeId,
        ?int $faculteId
    ): array {
        // Sessions par statut
        $sessionsStats = [
            'total' => $sessions->count(),
            'draft' => $sessions->where('statut', 'draft')->count(),
            'open' => $sessions->where('statut', 'open')->count(),
            'ongoing' => $sessions->where('statut', 'ongoing')->count(),
            'closed' => $sessions->where('statut', 'closed')->count(),
            'locked' => $sessions->where('statut', 'locked')->count(),
            'archived' => $sessions->where('statut', 'archived')->count(),
            'actives' => $sessions->whereIn('statut', ['open', 'ongoing'])->count(),
        ];

        // Épreuves par statut
        $epreuvesParStatut = $epreuves->groupBy('statut')->map->count()->all();
        $totalEpreuves = $epreuves->count();
        $sansSalle = $epreuves->whereNull('salle_id')->count();
        $sansSurveillant = $epreuves->filter(fn (Epreuve $e) => $e->surveillants->isEmpty())->count();
        $publiees = $epreuves->where('statut', 'published')->count();
        $planifiees = $epreuves->whereIn('statut', ['scheduled', 'published', 'ongoing', 'completed'])->count();

        // Candidats et documents dans le périmètre
        $totalCandidatures = $epreuves->sum('candidats_count');

        $scopeEpreuve = fn ($q) => $q
            ->when($anneeId !== null, fn ($r) => $r->where('annee_universitaire_id', $anneeId))
            ->when($faculteId !== null, fn ($r) => $r->pourFaculteId($faculteId));

        $totalDocuments = DocumentExamenGenere::whereHas('epreuve', $scopeEpreuve)->count();

        // Salles et capacités
        $sallesActives = $salles->where('actif', true);
        $sallesSansCapacite = $salles->filter(fn (Room $s) => $s->capacite === null || $s->capacite <= 0)->count();
        $sallesOccupeesIds = $epreuves->pluck('salle_id')->filter()->unique()->all();

        // Examens
        $examensQuery = Examen::query()
            ->when($anneeId !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId));
        $examensTotal = $examensQuery->count();
        $examensPublies = (clone $examensQuery)->where('statut', 'published')->count();

        $pointsAttention = $sansSalle + $sallesSansCapacite + $sansSurveillant;

        return [
            'sessions' => $sessionsStats,
            'examens' => [
                'total' => $examensTotal,
                'publies' => $examensPublies,
            ],
            'epreuves' => [
                'total' => $totalEpreuves,
                'par_statut' => $epreuvesParStatut,
                'planifiees' => $planifiees,
                'publiees' => $publiees,
                'sans_salle' => $sansSalle,
                'sans_surveillant' => $sansSurveillant,
            ],
            'candidats' => [
                'total_candidatures' => $totalCandidatures,
            ],
            'documents' => [
                'total' => $totalDocuments,
            ],
            'salles' => [
                'total' => $salles->count(),
                'actives' => $sallesActives->count(),
                'utilisees' => count($sallesOccupeesIds),
                'sans_capacite' => $sallesSansCapacite,
            ],
            'points_attention' => $pointsAttention,
        ];
    }

    /**
     * Détecte et structure les conflits en 4 familles distinctes (Salles, Candidats, Surveillants, Planning).
     *
     * @param  Collection<int, Epreuve>  $epreuves
     * @param  Collection<int, Room>  $salles
     * @param  Collection<int, SessionExamen>  $sessions
     * @return array<string, mixed>
     */
    public function detecterConflitsConsolides(
        Collection $epreuves,
        Collection $salles,
        Collection $sessions
    ): array {
        $conflitsSalles = [];
        $conflitsCandidats = [];
        $conflitsSurveillants = [];
        $conflitsPlanning = [];

        $sallesById = $salles->keyBy('id');
        $sessionsById = $sessions->keyBy('id');

        // Parcours d'analyse des épreuves
        foreach ($epreuves as $index => $epreuve) {
            $dateStr = $epreuve->date?->format('Y-m-d');
            $hDebut = substr((string) $epreuve->heure_debut, 0, 5);
            $hFin = substr((string) $epreuve->heure_fin, 0, 5);

            // FAMILLE D — PLANNING & CALENDRIER
            // D-1. Heures incohérentes
            if (! empty($hDebut) && ! empty($hFin) && $hDebut >= $hFin) {
                $conflitsPlanning[] = [
                    'id' => 'CONF-PLAN-TIME-'.$epreuve->id,
                    'famille' => 'planning',
                    'type' => 'horaire_incoherent',
                    'gravite' => 'CRITICAL',
                    'titre' => 'Horaire incohérent',
                    'description' => "L'heure de début ({$hDebut}) est postérieure ou égale à l'heure de fin ({$hFin}).",
                    'epreuve_id' => $epreuve->id,
                    'epreuve_code' => $epreuve->code,
                    'regle_violee' => 'C-HARD-01',
                    'menu_resolution' => 'admin.evaluation.examens.planification',
                    'action_label' => 'Corriger le créneau',
                ];
            }

            // D-2. Épreuve hors période de session
            if ($epreuve->session_id !== null && isset($sessionsById[$epreuve->session_id]) && $dateStr) {
                /** @var SessionExamen $session */
                $session = $sessionsById[$epreuve->session_id];
                $debSession = $session->date_debut?->format('Y-m-d');
                $finSession = $session->date_fin?->format('Y-m-d');

                if ($debSession && $finSession && ($dateStr < $debSession || $dateStr > $finSession)) {
                    $conflitsPlanning[] = [
                        'id' => 'CONF-PLAN-OUT-'.$epreuve->id,
                        'famille' => 'planning',
                        'type' => 'hors_session',
                        'gravite' => 'CRITICAL',
                        'titre' => 'Épreuve hors période de session',
                        'description' => "La date du {$epreuve->date?->format('d/m/Y')} est hors de la session {$session->nom} ({$session->date_debut?->format('d/m/Y')} au {$session->date_fin?->format('d/m/Y')}).",
                        'epreuve_id' => $epreuve->id,
                        'epreuve_code' => $epreuve->code,
                        'regle_violee' => 'C-HARD-06',
                        'menu_resolution' => 'admin.evaluation.examens.sessions',
                        'action_label' => 'Ajuster les dates',
                    ];
                }
            }

            // FAMILLE A — SALLES & CAPACITÉS
            if ($epreuve->salle_id !== null) {
                $salle = $sallesById->get($epreuve->salle_id);

                if (! $salle) {
                    $conflitsSalles[] = [
                        'id' => 'CONF-ROOM-NOTFOUND-'.$epreuve->id,
                        'famille' => 'salles',
                        'type' => 'salle_inconnue',
                        'gravite' => 'CRITICAL',
                        'titre' => 'Salle introuvable',
                        'description' => "La salle affectée #{$epreuve->salle_id} n'existe plus ou a été supprimée.",
                        'epreuve_id' => $epreuve->id,
                        'epreuve_code' => $epreuve->code,
                        'regle_violee' => 'C-HARD-03',
                        'menu_resolution' => 'admin.evaluation.examens.salles',
                        'action_label' => 'Affecter une salle existante',
                    ];
                } else {
                    // Salle inactive
                    if (! $salle->actif) {
                        $conflitsSalles[] = [
                            'id' => 'CONF-ROOM-INACT-'.$epreuve->id,
                            'famille' => 'salles',
                            'type' => 'salle_inactive',
                            'gravite' => 'CRITICAL',
                            'titre' => "Salle fermée ou inactive ({$salle->nom})",
                            'description' => "La salle '{$salle->nom}' est marquée comme fermée/inactive.",
                            'epreuve_id' => $epreuve->id,
                            'epreuve_code' => $epreuve->code,
                            'regle_violee' => 'C-HARD-03',
                            'menu_resolution' => 'admin.evaluation.examens.salles',
                            'action_label' => 'Changer de salle',
                        ];
                    }

                    // Capacité inconnue
                    if ($salle->capacite === null || $salle->capacite <= 0) {
                        $conflitsSalles[] = [
                            'id' => 'CONF-ROOM-NOCAP-'.$epreuve->id,
                            'famille' => 'salles',
                            'type' => 'capacite_inconnue',
                            'gravite' => 'WARNING',
                            'titre' => "Capacité non vérifiée ({$salle->nom})",
                            'description' => "La capacité de la salle '{$salle->nom}' n'est pas renseignée. Validation impossible.",
                            'epreuve_id' => $epreuve->id,
                            'epreuve_code' => $epreuve->code,
                            'regle_violee' => 'C-HARD-03',
                            'menu_resolution' => 'admin.evaluation.examens.salles',
                            'action_label' => 'Renseigner la capacité',
                        ];
                    } else {
                        // Capacité d'examen utile (50% standard anti-fraude)
                        $capaUtile = (int) floor($salle->capacite * 0.50);
                        $effectifRequis = $epreuve->capacite_necessaire > 0 ? (int) $epreuve->capacite_necessaire : (int) $epreuve->candidats_count;

                        if ($effectifRequis > 0 && $capaUtile < $effectifRequis) {
                            $conflitsSalles[] = [
                                'id' => 'CONF-ROOM-OVER-'.$epreuve->id,
                                'famille' => 'salles',
                                'type' => 'capacite_depassee',
                                'gravite' => 'CRITICAL',
                                'titre' => "Capacité insuffisante ({$salle->nom})",
                                'description' => "Capacité utile examen de {$capaUtile} places pour {$effectifRequis} candidats requis (déficit de ".($effectifRequis - $capaUtile).' places).',
                                'epreuve_id' => $epreuve->id,
                                'epreuve_code' => $epreuve->code,
                                'regle_violee' => 'C-HARD-02',
                                'menu_resolution' => 'admin.evaluation.examens.salles',
                                'action_label' => 'Ventiler sur une autre salle',
                            ];
                        }
                    }
                }
            } else {
                // Épreuve sans salle
                $conflitsSalles[] = [
                    'id' => 'CONF-ROOM-MISSING-'.$epreuve->id,
                    'famille' => 'salles',
                    'type' => 'salle_manquante',
                    'gravite' => 'WARNING',
                    'titre' => "Salle non attribuée (#{$epreuve->code})",
                    'description' => "L'épreuve '{$epreuve->matiere?->nom}' n'a pas de salle affectée.",
                    'epreuve_id' => $epreuve->id,
                    'epreuve_code' => $epreuve->code,
                    'regle_violee' => 'C-HARD-01',
                    'menu_resolution' => 'admin.evaluation.examens.planification',
                    'action_label' => 'Affecter une salle',
                ];
            }

            // FAMILLE C — SURVEILLANCE
            if ($epreuve->surveillants->isEmpty()) {
                $conflitsSurveillants[] = [
                    'id' => 'CONF-SURV-MISSING-'.$epreuve->id,
                    'famille' => 'surveillants',
                    'type' => 'surveillant_manquant',
                    'gravite' => 'WARNING',
                    'titre' => "Surveillance non assurée (#{$epreuve->code})",
                    'description' => "Aucun surveillant affecté à l'épreuve '{$epreuve->matiere?->nom}'.",
                    'epreuve_id' => $epreuve->id,
                    'epreuve_code' => $epreuve->code,
                    'regle_violee' => 'C-HARD-05',
                    'menu_resolution' => 'admin.evaluation.examens.surveillance',
                    'action_label' => 'Affecter des surveillants',
                ];
            }

            // Détection des chevauchements inter-épreuves (Salles, Candidats, Surveillants)
            for ($j = $index + 1; $j < $epreuves->count(); $j++) {
                /** @var Epreuve $autre */
                $autre = $epreuves->get($j);

                // Chevauchement horaire le même jour
                if ($dateStr && $autre->date?->format('Y-m-d') === $dateStr) {
                    $autreHDebut = substr((string) $autre->heure_debut, 0, 5);
                    $autreHFin = substr((string) $autre->heure_fin, 0, 5);

                    if ($hDebut < $autreHFin && $autreHDebut < $hFin) {
                        // Conflit A-CHEV : Même salle réservée deux fois
                        if ($epreuve->salle_id !== null && $epreuve->salle_id === $autre->salle_id) {
                            $nomSalle = $sallesById->get($epreuve->salle_id)?->nom ?? "Salle #{$epreuve->salle_id}";
                            $conflitsSalles[] = [
                                'id' => 'CONF-ROOM-DOUBLE-'.$epreuve->id.'-'.$autre->id,
                                'famille' => 'salles',
                                'type' => 'double_reservation',
                                'gravite' => 'CRITICAL',
                                'titre' => "Double réservation de salle ({$nomSalle})",
                                'description' => "La salle '{$nomSalle}' est affectée simultanément à #{$epreuve->code} ({$hDebut}-{$hFin}) et #{$autre->code} ({$autreHDebut}-{$autreHFin}).",
                                'epreuve_id' => $epreuve->id,
                                'epreuve_code' => $epreuve->code,
                                'autre_epreuve_id' => $autre->id,
                                'regle_violee' => 'C-HARD-01',
                                'menu_resolution' => 'admin.evaluation.examens.planification',
                                'action_label' => 'Résoudre le conflit de créneau',
                            ];
                        }

                        // Conflit B-CHEV : Même groupe convoqué deux fois
                        if ($epreuve->groupe_id !== null && $epreuve->groupe_id === $autre->groupe_id) {
                            $nomGroupe = $epreuve->groupe?->nom ?? "Groupe #{$epreuve->groupe_id}";
                            $conflitsCandidats[] = [
                                'id' => 'CONF-CAND-GROUP-'.$epreuve->id.'-'.$autre->id,
                                'famille' => 'candidats',
                                'type' => 'conflit_groupe',
                                'gravite' => 'CRITICAL',
                                'titre' => "Chevauchement d'épreuves pour {$nomGroupe}",
                                'description' => "Le groupe '{$nomGroupe}' est convoqué simultanément à deux épreuves (#{$epreuve->code} et #{$autre->code}).",
                                'epreuve_id' => $epreuve->id,
                                'epreuve_code' => $epreuve->code,
                                'autre_epreuve_id' => $autre->id,
                                'regle_violee' => 'C-HARD-04',
                                'menu_resolution' => 'admin.evaluation.examens.planification',
                                'action_label' => 'Décaler une des épreuves',
                            ];
                        }

                        // Conflit C-CHEV : Même surveillant affecté deux fois
                        $survIds1 = $epreuve->surveillants->pluck('teacher_id')->filter()->all();
                        $survIds2 = $autre->surveillants->pluck('teacher_id')->filter()->all();
                        $doublons = array_intersect($survIds1, $survIds2);

                        if (! empty($doublons)) {
                            $conflitsSurveillants[] = [
                                'id' => 'CONF-SURV-DOUBLE-'.$epreuve->id.'-'.$autre->id,
                                'famille' => 'surveillants',
                                'type' => 'surveillant_double',
                                'gravite' => 'CRITICAL',
                                'titre' => 'Surveillant en double affectation',
                                'description' => count($doublons)." enseignant(s) affecté(s) simultanément aux épreuves #{$epreuve->code} et #{$autre->code}.",
                                'epreuve_id' => $epreuve->id,
                                'epreuve_code' => $epreuve->code,
                                'autre_epreuve_id' => $autre->id,
                                'regle_violee' => 'C-HARD-05',
                                'menu_resolution' => 'admin.evaluation.examens.surveillance',
                                'action_label' => 'Remplacer le surveillant',
                            ];
                        }
                    }
                }
            }
        }

        $tousConflits = array_merge($conflitsSalles, $conflitsCandidats, $conflitsSurveillants, $conflitsPlanning);
        $totalCritiques = count(array_filter($tousConflits, fn ($c) => $c['gravite'] === 'CRITICAL'));
        $totalAvertissements = count(array_filter($tousConflits, fn ($c) => $c['gravite'] === 'WARNING'));

        return [
            'famille_salles' => $conflitsSalles,
            'famille_candidats' => $conflitsCandidats,
            'famille_surveillants' => $conflitsSurveillants,
            'famille_planning' => $conflitsPlanning,
            'totaux' => [
                'total' => count($tousConflits),
                'critiques' => $totalCritiques,
                'avertissements' => $totalAvertissements,
                'salles' => count($conflitsSalles),
                'candidats' => count($conflitsCandidats),
                'surveillants' => count($conflitsSurveillants),
                'planning' => count($conflitsPlanning),
            ],
            'liste_complete' => $tousConflits,
        ];
    }

    /**
     * Calcule le score de préparation multi-dimensionnel de chaque session.
     *
     * @param  Collection<int, SessionExamen>  $sessions
     * @param  Collection<int, Epreuve>  $epreuves
     * @param  array<string, mixed>  $matriceConflits
     * @return array<int, array<string, mixed>>
     */
    public function calculerScoresSessions(
        Collection $sessions,
        Collection $epreuves,
        array $matriceConflits
    ): array {
        $resultats = [];

        foreach ($sessions as $session) {
            $epreuvesSession = $epreuves->where('session_id', $session->id);
            $totalEpr = $epreuvesSession->count();

            $bloquants = [];
            $avertissements = [];

            // Dimension 1 : Paramétrage & Période (15 pts)
            $pointsD1 = 0;
            if ($session->date_debut && $session->date_fin && $session->date_debut <= $session->date_fin) {
                $pointsD1 += 8;
            } else {
                $bloquants[] = 'Période de session invalide ou non renseignée.';
            }
            if ($totalEpr > 0) {
                $pointsD1 += 7;
            } else {
                $avertissements[] = "Aucune épreuve rattachée à la session {$session->nom}.";
            }

            // Dimension 2 : Candidatures & Éligibilité (15 pts)
            $pointsD2 = 0;
            $candidatsCount = $epreuvesSession->sum('candidats_count');
            if ($totalEpr > 0 && $candidatsCount > 0) {
                $ratioCandidats = $epreuvesSession->filter(fn ($e) => $e->candidats_count > 0)->count() / $totalEpr;
                $pointsD2 = (int) round($ratioCandidats * 15);
                if ($ratioCandidats < 1.0) {
                    $avertissements[] = 'Certaines épreuves n’ont pas encore de candidats générés.';
                }
            } elseif ($totalEpr > 0) {
                $avertissements[] = 'Listes de candidats non générées.';
            }

            // Dimension 3 : Planification temporelle & Créneaux (20 pts)
            $pointsD3 = 0;
            if ($totalEpr > 0) {
                $avecHoraire = $epreuvesSession->filter(fn ($e) => ! empty($e->date) && ! empty($e->heure_debut) && ! empty($e->heure_fin))->count();
                $ratioHoraire = $avecHoraire / $totalEpr;
                $pointsD3 = (int) round($ratioHoraire * 20);

                // Détection conflits planning propres à cette session
                $conflitsPlanSession = array_filter(
                    $matriceConflits['famille_planning'],
                    fn ($c) => $epreuvesSession->pluck('id')->contains($c['epreuve_id'])
                );
                if (! empty($conflitsPlanSession)) {
                    $bloquants[] = count($conflitsPlanSession).' conflit(s) de planning ou d’horaires détecté(s).';
                }
            }

            // Dimension 4 : Salles & Capacités (20 pts)
            $pointsD4 = 0;
            if ($totalEpr > 0) {
                $avecSalle = $epreuvesSession->whereNotNull('salle_id')->count();
                $ratioSalle = $avecSalle / $totalEpr;
                $pointsD4 = (int) round($ratioSalle * 20);

                // Conflits de salles pour cette session
                $conflitsSallesSession = array_filter(
                    $matriceConflits['famille_salles'],
                    fn ($c) => $epreuvesSession->pluck('id')->contains($c['epreuve_id'])
                );
                $critiquesSalles = array_filter($conflitsSallesSession, fn ($c) => $c['gravite'] === 'CRITICAL');
                if (! empty($critiquesSalles)) {
                    $bloquants[] = count($critiquesSalles).' anomalie(s) bloquante(s) de salle ou capacité.';
                }
            }

            // Dimension 5 : Surveillance (15 pts)
            $pointsD5 = 0;
            if ($totalEpr > 0) {
                $avecSurveillant = $epreuvesSession->filter(fn ($e) => $e->surveillants->isNotEmpty())->count();
                $ratioSurv = $avecSurveillant / $totalEpr;
                $pointsD5 = (int) round($ratioSurv * 15);

                if ($ratioSurv < 1.0) {
                    $avertissements[] = ($totalEpr - $avecSurveillant).' épreuve(s) sans surveillant affecté.';
                }
            }

            // Dimension 6 : Documents & Convocations (10 pts)
            $pointsD6 = 5; // Modèles et infrastructure prêts
            if ($session->statut === 'locked' || $session->statut === 'closed') {
                $pointsD6 = 10;
            }

            // Dimension 7 : Validation Administrative (5 pts)
            $pointsD7 = 0;
            if ($session->statut === 'open' || $session->statut === 'ongoing') {
                $pointsD7 = 5;
            } elseif ($session->statut === 'draft') {
                $pointsD7 = 2;
            }

            $scoreGlobal = $pointsD1 + $pointsD2 + $pointsD3 + $pointsD4 + $pointsD5 + $pointsD6 + $pointsD7;

            // Règle d'or : éligibilité opérationnelle
            $statutEligibilite = 'ELIGIBLE';
            if (! empty($bloquants)) {
                $statutEligibilite = 'BLOQUE';
            } elseif ($scoreGlobal < 70) {
                $statutEligibilite = 'A_COMPLETER';
            }

            $resultats[] = [
                'session' => $session,
                'score_global' => $scoreGlobal,
                'statut_eligibilite' => $statutEligibilite,
                'dimensions' => [
                    'D1' => ['libelle' => 'Paramétrage & Période', 'score' => $pointsD1, 'max' => 15],
                    'D2' => ['libelle' => 'Candidats & Éligibilité', 'score' => $pointsD2, 'max' => 15],
                    'D3' => ['libelle' => 'Planification & Créneaux', 'score' => $pointsD3, 'max' => 20],
                    'D4' => ['libelle' => 'Salles & Capacités', 'score' => $pointsD4, 'max' => 20],
                    'D5' => ['libelle' => 'Surveillance', 'score' => $pointsD5, 'max' => 15],
                    'D6' => ['libelle' => 'Documents & Convocations', 'score' => $pointsD6, 'max' => 10],
                    'D7' => ['libelle' => 'Validation Administrative', 'score' => $pointsD7, 'max' => 5],
                ],
                'bloquants' => $bloquants,
                'avertissements' => $avertissements,
                'total_epreuves' => $totalEpr,
                'candidats_count' => $candidatsCount,
            ];
        }

        return $resultats;
    }

    /**
     * Analyse en profondeur les capacités, la saturation et les risques des salles.
     *
     * @param  Collection<int, Room>  $salles
     * @param  Collection<int, Epreuve>  $epreuves
     * @return array<string, mixed>
     */
    public function analyserCapacitesSalles(Collection $salles, Collection $epreuves): array
    {
        $sallesActives = $salles->where('actif', true);
        $capaciteNominaleTotale = $sallesActives->sum('capacite');
        $capaciteUtileTotale = (int) floor($capaciteNominaleTotale * 0.50);

        // Répartition par bâtiment
        $parBatiment = $salles->groupBy(fn (Room $s) => $s->batimentRelation?->nom ?? $s->batiment ?? 'Non assigné')
            ->map(function ($groupeSalles, $nomBatiment) {
                return [
                    'nom' => $nomBatiment,
                    'total_salles' => $groupeSalles->count(),
                    'actives' => $groupeSalles->where('actif', true)->count(),
                    'capacite_nominale' => $groupeSalles->where('actif', true)->sum('capacite'),
                    'capacite_utile' => (int) floor($groupeSalles->where('actif', true)->sum('capacite') * 0.50),
                    'sans_capacite' => $groupeSalles->filter(fn ($s) => $s->capacite === null || $s->capacite <= 0)->count(),
                ];
            })->values()->all();

        // Taux de saturation globale estimé
        $effectifGlobalPlanifie = $epreuves->whereNotNull('salle_id')->sum(function (Epreuve $e) {
            return $e->capacite_necessaire > 0 ? $e->capacite_necessaire : $e->candidats_count;
        });

        $tauxSaturation = $capaciteUtileTotale > 0
            ? round(($effectifGlobalPlanifie / $capaciteUtileTotale) * 100, 1)
            : 0;

        return [
            'total_salles' => $salles->count(),
            'salles_actives' => $sallesActives->count(),
            'capacite_nominale_totale' => $capaciteNominaleTotale,
            'capacite_utile_totale' => $capaciteUtileTotale,
            'effectif_planifie' => $effectifGlobalPlanifie,
            'taux_saturation_global' => $tauxSaturation,
            'par_batiment' => $parBatiment,
        ];
    }

    /**
     * Évalue le respect du workflow à 4 yeux et l'état des propositions de planning.
     *
     * @param  Collection<int, Epreuve>  $epreuves
     * @param  Collection<int, SessionExamen>  $sessions
     * @return array<string, mixed>
     */
    public function evaluerGouvernanceWorkflow(
        Collection $epreuves,
        Collection $sessions,
        ?User $user
    ): array {
        $epreuvesDraft = $epreuves->where('statut', 'draft')->count();
        $epreuvesPlanifiees = $epreuves->where('statut', 'scheduled')->count();
        $epreuvesPubliees = $epreuves->where('statut', 'published')->count();

        // Sessions ouvertes à la validation
        $sessionsEnAttente = $sessions->where('statut', 'draft')->count();

        // Règle 4 yeux : interdiction de s'auto-approuver
        $antiAutoApprobationActive = true;

        return [
            'statut_general' => $epreuvesPubliees > 0 && $epreuvesPlanifiees === 0 ? 'PUBLIE' : 'EN_PREPARATION',
            'anti_auto_approbation_active' => $antiAutoApprobationActive,
            'propositions_en_cours' => [
                'epreuves_brouillon' => $epreuvesDraft,
                'epreuves_planifiees_a_valider' => $epreuvesPlanifiees,
                'epreuves_publiees' => $epreuvesPubliees,
                'sessions_brouillon' => $sessionsEnAttente,
            ],
            'peut_publier' => $user !== null && $user->can('evaluations.view'),
        ];
    }

    /**
     * Évalue l'indice de fiabilité et de complétude des données.
     *
     * @param  Collection<int, SessionExamen>  $sessions
     * @param  Collection<int, Epreuve>  $epreuves
     * @param  Collection<int, Room>  $salles
     * @return array<string, mixed>
     */
    public function evaluerFiabiliteDonnees(
        Collection $sessions,
        Collection $epreuves,
        Collection $salles
    ): array {
        $points = 100;
        $avertissements = [];

        // Pénalités de fiabilité
        $sallesSansCapacite = $salles->filter(fn ($s) => $s->capacite === null || $s->capacite <= 0)->count();
        if ($sallesSansCapacite > 0) {
            $points -= min(30, $sallesSansCapacite * 5);
            $avertissements[] = "{$sallesSansCapacite} salle(s) sans capacité vérifiée (estimation de saturation partielle).";
        }

        $epreuvesSansCandidats = $epreuves->filter(fn ($e) => $e->candidats_count === 0)->count();
        if ($epreuvesSansCandidats > 0) {
            $points -= min(25, $epreuvesSansCandidats * 2);
            $avertissements[] = "{$epreuvesSansCandidats} épreuve(s) sans effectif de candidats généré.";
        }

        $points = max(10, $points);

        $statut = 'fiable';
        if ($points < 50) {
            $statut = 'incomplet';
        } elseif ($points < 80) {
            $statut = 'partiel';
        }

        return [
            'indice' => $points,
            'statut' => $statut,
            'avertissements' => $avertissements,
            'derniere_mise_a_jour' => now()->format('d/m/Y H:i'),
        ];
    }

    /**
     * Construit la matrice d'intégration vers les 12 menus fonctionnels.
     *
     * @param  array<string, mixed>  $kpis
     * @param  array<string, mixed>  $matriceConflits
     * @return array<int, array<string, mixed>>
     */
    public function construireGrille12Menus(
        ?int $anneeId,
        ?int $faculteId,
        array $kpis,
        array $matriceConflits
    ): array {
        $params = array_filter(['annee_id' => $anneeId]);

        return [
            [
                'numero' => 1,
                'nom' => 'Tableau de bord',
                'description' => 'Centre de pilotage consolidé des examens',
                'route' => route('admin.evaluation.examens.tableau-de-bord', $params),
                'permission' => 'evaluations.view',
                'statut_badge' => 'Principal',
                'indicateur' => $kpis['sessions']['total'].' sessions',
                'couleur' => 'blue',
            ],
            [
                'numero' => 2,
                'nom' => 'Sessions d\'examens',
                'description' => 'Cycles d\'examens, périodes et gouvernance',
                'route' => route('admin.evaluation.examens.sessions', $params),
                'permission' => 'sessions_examen.view',
                'statut_badge' => $kpis['sessions']['actives'].' actives',
                'indicateur' => $kpis['sessions']['total'].' au total',
                'couleur' => 'indigo',
            ],
            [
                'numero' => 3,
                'nom' => 'Configurations',
                'description' => 'Barèmes, seuils, anonymat et coefficients',
                'route' => route('admin.evaluation.examens.configurations', $params),
                'permission' => 'evaluations.view',
                'statut_badge' => 'Règles',
                'indicateur' => 'Actives',
                'couleur' => 'slate',
            ],
            [
                'numero' => 4,
                'nom' => 'Éligibilité',
                'description' => 'Contrôle des inscriptions pédagogiques et prérequis',
                'route' => route('admin.evaluation.examens.eligibilite', $params),
                'permission' => 'evaluations.view',
                'statut_badge' => 'Audit',
                'indicateur' => $kpis['candidats']['total_candidatures'].' inscrits',
                'couleur' => 'emerald',
            ],
            [
                'numero' => 5,
                'nom' => 'Groupes d\'étudiants',
                'description' => 'Cohortes, tiers-temps et aménagements spécifiques',
                'route' => route('admin.evaluation.examens.groupes', $params),
                'permission' => 'epreuves.view',
                'statut_badge' => 'Cohortes',
                'indicateur' => 'Par niveau',
                'couleur' => 'violet',
            ],
            [
                'numero' => 6,
                'nom' => 'Salles & Capacités',
                'description' => 'Capacités réelles, amphis, équipements et PMR',
                'route' => route('admin.evaluation.examens.salles', $params),
                'permission' => 'evaluations.view',
                'statut_badge' => $kpis['salles']['sans_capacite'] > 0 ? $kpis['salles']['sans_capacite'].' non vérifiées' : 'Validées',
                'indicateur' => $kpis['salles']['total'].' salles',
                'couleur' => $kpis['salles']['sans_capacite'] > 0 ? 'amber' : 'emerald',
            ],
            [
                'numero' => 7,
                'nom' => 'Planification',
                'description' => 'Ordonnancement temporel et détection de conflits',
                'route' => route('admin.evaluation.examens.planification', $params),
                'permission' => 'epreuves.view',
                'statut_badge' => $matriceConflits['totaux']['total'] > 0 ? $matriceConflits['totaux']['total'].' conflits' : 'Optimal',
                'indicateur' => $kpis['epreuves']['planifiees'].'/'.$kpis['epreuves']['total'].' planifiées',
                'couleur' => $matriceConflits['totaux']['total'] > 0 ? 'rose' : 'emerald',
            ],
            [
                'numero' => 8,
                'nom' => 'Surveillance',
                'description' => 'Affectation des enseignants et émargement',
                'route' => route('admin.evaluation.examens.surveillance', $params),
                'permission' => 'epreuves.view',
                'statut_badge' => $kpis['epreuves']['sans_surveillant'] > 0 ? $kpis['epreuves']['sans_surveillant'].' manquants' : 'Complet',
                'indicateur' => 'Ratios',
                'couleur' => $kpis['epreuves']['sans_surveillant'] > 0 ? 'amber' : 'emerald',
            ],
            [
                'numero' => 9,
                'nom' => 'Simulation',
                'description' => 'Moteur CSP & comparaison de scénarios multi-stratégies',
                'route' => route('admin.evaluation.examens.simulation', $params),
                'permission' => 'evaluations.view',
                'statut_badge' => 'Virtuel',
                'indicateur' => '0 écriture',
                'couleur' => 'sky',
            ],
            [
                'numero' => 10,
                'nom' => 'Publication',
                'description' => 'Workflow 4 yeux, validation et publication officielle',
                'route' => route('admin.evaluation.examens.publication', $params),
                'permission' => 'evaluations.view',
                'statut_badge' => $kpis['epreuves']['publiees'].' publiées',
                'indicateur' => 'Gouvernance',
                'couleur' => 'teal',
            ],
            [
                'numero' => 11,
                'nom' => 'Convocations',
                'description' => 'Génération des convocations PDF & QR codes',
                'route' => route('admin.evaluation.examens.convocations', $params),
                'permission' => 'evaluations.view',
                'statut_badge' => 'Étudiants',
                'indicateur' => $kpis['documents']['total'].' générées',
                'couleur' => 'purple',
            ],
            [
                'numero' => 12,
                'nom' => 'Historique & Audit',
                'description' => 'Journal immuable des transitions et conformité',
                'route' => route('admin.evaluation.examens.historique', $params),
                'permission' => 'evaluations.view',
                'statut_badge' => 'Immuable',
                'indicateur' => 'Audit log',
                'couleur' => 'slate',
            ],
        ];
    }
}
