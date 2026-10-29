<?php

namespace App\Modules\Schedule\Services;

use App\Models\Matiere;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleAvailability;
use App\Modules\Schedule\Models\ScheduleChange;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Models\TimeSlot;
use App\Modules\Schedule\Models\TimetableConstraint;
use App\Modules\Schedule\Models\TimetableGeneration;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 4 (Smart Timetables) — Moteur de génération automatique d'emplois du temps.
 * Résout le problème de satisfaction de contraintes (CSP - Constraint Satisfaction Problem)
 * pour produire un planning réalisable sans aucune violation dure, en respectant
 * les principes d'optimisation ergonomiques (trous, équilibre, transits, préférences).
 *
 * PHASE B (Smart Timetables) — branchement des pondérations DÉCLARÉES :
 * le registre de contraintes (`TimetableConstraint`), les paramètres bornés
 * `planning.generation.*` (Phase A) et les plages `souhaitee` des ressources
 * pilotent désormais l'ordonnancement des candidats. Les contraintes dures
 * (conflits, indisponibilités) restent garanties par `ScheduleConflictService`.
 *
 * RÈGLE ABSOLUE : Le planning produit reste en statut BROUILLON (aucune publication automatique).
 */
class ScheduleGeneratorService
{
    /**
     * Statuts dans lesquels une génération ne doit JAMAIS écrire
     * (lecture seule : publication et archivage sont définitifs).
     */
    private const STATUTS_NON_MODIFIABLES = ['publie', 'verrouille', 'archive'];

    /** Seuil de battement (minutes) au-delà duquel deux séances ne sont plus « chaînées ». */
    private const SEUIL_ENCHAINEMENT_MINUTES = 20;

    /** Plafond de requêtes d'affichage : mémorisation par instance de service. */

    /** @var array<string, array<string, mixed>> */
    private array $contextesQualite = [];

    /** @var array<int, string|null> */
    private array $codesMatieres = [];

    /** @var array<string, int> */
    private array $effectifsMemoises = [];

    public function __construct(
        private readonly ScheduleConflictService $conflictService,
        private readonly ScheduleConstraintChecker $checker,
        private readonly ScheduleConstraintRegistry $registry,
        private readonly ScheduleCalendarService $calendarService,
        private readonly ScheduleGenerationSettings $settings,
        private readonly SchedulePedagogieService $pedagogieService,
    ) {}

    /**
     * Génère et persiste un emploi du temps réalisable pour le planning donné.
     *
     * @param  array<string, mixed>  $options
     */
    public function generer(Schedule $schedule, array $options = []): TimetableGeneration
    {
        return $this->executerGeneration($schedule, array_merge($options, ['dry_run' => false]));
    }

    /**
     * Exécute une simulation à blanc (dry-run) sans aucune écriture dans schedule_slots.
     *
     * @param  array<string, mixed>  $options
     */
    public function simuler(Schedule $schedule, array $options = []): TimetableGeneration
    {
        return $this->executerGeneration($schedule, array_merge($options, ['dry_run' => true]));
    }

    /**
     * Découvre et prépare les demandes de séances depuis les enseignements de la maquette.
     *
     * @return Collection<int, SeanceDemande>
     */
    public function preparerDemandesDepuisEnseignements(Schedule $schedule): Collection
    {
        $demandes = collect();
        $groupe = $schedule->groupe;
        if ($groupe === null) {
            return $demandes;
        }

        // 1. Recherche des enseignements associés au groupe ou à son niveau/semestre
        $enseignements = Enseignement::query()
            ->where('annee_universitaire_id', $schedule->annee_universitaire_id)
            ->where(function ($q) use ($groupe, $schedule) {
                $q->whereHas('groupes', fn ($g) => $g->where('groups.id', $groupe->id))
                    ->orWhere(function ($sub) use ($groupe, $schedule) {
                        $sub->whereHas('matiere', function ($m) use ($groupe, $schedule) {
                            // PHASE B : scope canonique du périmètre faculté
                            // (l'ancien filtre visait `matieres.faculte_id`,
                            // colonne inexistante → aucun enseignement trouvé).
                            $m->pourFaculte($groupe->faculte_id)
                                ->when($groupe->niveau !== null, fn ($mq) => $mq->where('niveau', $groupe->niveau))
                                ->when($schedule->semestre !== null, fn ($mq) => $mq->where('semestre', $schedule->semestre));
                        });
                    });
            })
            ->with(['matiere', 'enseignants', 'groupes', 'salles'])
            ->get();

        foreach ($enseignements as $enseignement) {
            $bilan = $this->pedagogieService->bilanVolumes($enseignement, $schedule->id);
            $matiere = $enseignement->matiere;
            $enseignantPrincipal = $enseignement->enseignants->first();

            // Demandes CM (Cours Magistraux)
            if (($bilan['cm']['reste'] ?? 0) >= 1.5) {
                $nbSeancesCm = (int) round(($bilan['cm']['reste'] / 14) / 1.5);
                $nbSeancesCm = max(1, $nbSeancesCm);

                for ($i = 1; $i <= $nbSeancesCm; $i++) {
                    $demandes->push(new SeanceDemande(
                        id: "cm_{$enseignement->id}_{$i}",
                        matiereId: $matiere->id,
                        enseignementId: $enseignement->id,
                        type: 'course',
                        titre: "CM {$matiere->code} #{$i}",
                        dureeMinutes: 90,
                        teacherId: $enseignantPrincipal?->id,
                        coEnseignantsIds: [],
                        roomId: null,
                        sousGroupeId: null,
                        equipementsRequis: [],
                        semaineParite: 'toutes',
                        priorite: 8,
                        typeSalleRequis: 'amphi'
                    ));
                }
            }

            // Demandes TD (Travaux Dirigés)
            if (($bilan['td']['reste'] ?? 0) >= 1.5) {
                $nbSeancesTd = (int) round(($bilan['td']['reste'] / 14) / 1.5);
                $nbSeancesTd = max(1, $nbSeancesTd);

                for ($i = 1; $i <= $nbSeancesTd; $i++) {
                    $demandes->push(new SeanceDemande(
                        id: "td_{$enseignement->id}_{$i}",
                        matiereId: $matiere->id,
                        enseignementId: $enseignement->id,
                        type: 'td',
                        titre: "TD {$matiere->code} #{$i}",
                        dureeMinutes: 90,
                        teacherId: $enseignantPrincipal?->id,
                        coEnseignantsIds: [],
                        roomId: null,
                        sousGroupeId: null,
                        equipementsRequis: [],
                        semaineParite: 'toutes',
                        priorite: 6,
                        typeSalleRequis: 'td'
                    ));
                }
            }

            // Demandes TP (Travaux Pratiques en sous-groupes)
            if (($bilan['tp']['reste'] ?? 0) >= 1.5) {
                $sousGroupes = $groupe->sousGroupes;
                if ($sousGroupes->isNotEmpty()) {
                    foreach ($sousGroupes as $sg) {
                        $demandes->push(new SeanceDemande(
                            id: "tp_{$enseignement->id}_{$sg->id}",
                            matiereId: $matiere->id,
                            enseignementId: $enseignement->id,
                            type: 'tp',
                            titre: "TP {$matiere->code} ({$sg->code})",
                            dureeMinutes: 90,
                            teacherId: $enseignantPrincipal?->id,
                            coEnseignantsIds: [],
                            roomId: null,
                            sousGroupeId: $sg->id,
                            equipementsRequis: [],
                            semaineParite: 'toutes',
                            priorite: 7,
                            typeSalleRequis: 'tp'
                        ));
                    }
                } else {
                    $demandes->push(new SeanceDemande(
                        id: "tp_{$enseignement->id}_1",
                        matiereId: $matiere->id,
                        enseignementId: $enseignement->id,
                        type: 'tp',
                        titre: "TP {$matiere->code}",
                        dureeMinutes: 90,
                        teacherId: $enseignantPrincipal?->id,
                        coEnseignantsIds: [],
                        roomId: null,
                        sousGroupeId: null,
                        equipementsRequis: [],
                        semaineParite: 'toutes',
                        priorite: 7,
                        typeSalleRequis: 'tp'
                    ));
                }
            }
        }

        // Si aucun enseignement formalisé, repli sur les matières du niveau
        if ($demandes->isEmpty()) {
            $matieres = Matiere::query()
                ->whereHas('ue.programme', fn ($p) => $p->where('faculte_id', $groupe->faculte_id))
                ->when($groupe->niveau !== null, fn ($q) => $q->where('niveau', $groupe->niveau))
                ->when($schedule->semestre !== null, fn ($q) => $q->where('semestre', $schedule->semestre))
                ->take(4)
                ->get();

            foreach ($matieres as $idx => $m) {
                $demandes->push(new SeanceDemande(
                    id: "cours_{$m->id}_".($idx + 1),
                    matiereId: $m->id,
                    type: 'course',
                    titre: "Cours {$m->nom}",
                    dureeMinutes: 90,
                    priorite: 5
                ));
            }
        }

        return $demandes;
    }

    /**
     * Moteur d'exécution CSP principal.
     *
     * @param  array<string, mixed>  $options
     */
    private function executerGeneration(Schedule $schedule, array $options): TimetableGeneration
    {
        $debutChrono = microtime(true);
        $faculteId = $schedule->groupe?->faculte_id;
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $ecraserBrouillon = (bool) ($options['ecraser_brouillon'] ?? false);
        $iterationMax = $this->bornerIterations((int) ($options['iteration_max'] ?? $this->settings->iterationMax()));
        $timeoutSecondes = $this->bornerTimeoutSecondes((int) ($options['timeout_secondes'] ?? 15));
        $auteurId = $options['auteur_id'] ?? null;

        // 0. PHASE B — garde-fou : aucune écriture dans un planning publié,
        // verrouillé ou archivé (seule la simulation reste possible).
        if (! $dryRun) {
            $this->verifierPlanningModifiable($schedule);
        }

        // 1. Récupération ou préparation des séances à planifier
        /** @var Collection<int, SeanceDemande> $demandes */
        $demandes = isset($options['seances_demandes'])
            ? collect($options['seances_demandes'])
            : $this->preparerDemandesDepuisEnseignements($schedule);

        // 2. Snapshot de l'état initial des créneaux (salles/co-enseignants
        // préchargés : le scoring qualitatif ne déclenche aucune requête).
        $slotsInitiaux = $schedule->slots()->where('status', '!=', 'cancelled')
            ->with(['room', 'coEnseignants'])
            ->get();
        $snapshotAvant = $slotsInitiaux->map(fn ($s) => $s->toArray())->all();

        // 3. Préparation des plages temporelles (TimeSlots) et ressources
        $plagesHoraires = $this->resoudrePlagesHoraires($faculteId, $options['jours_autorises'] ?? null);

        // 3 bis. PHASE B — pondérations déclarées + plages souhaitées,
        // chargées une seule fois pour toute la génération.
        $contexte = $this->construireContexteQualite($schedule, $faculteId, $plagesHoraires);
        $roomIdsDemandes = $demandes->pluck('roomId')->filter()->unique()->values();
        $salles = Room::where('actif', true)
            ->where(function ($q) use ($faculteId, $roomIdsDemandes) {
                if ($roomIdsDemandes->isNotEmpty()) {
                    $q->whereIn('id', $roomIdsDemandes);
                }
                $q->orWhere(function ($sub) use ($faculteId) {
                    $sub->whereNull('faculte_id');
                    if ($faculteId !== null) {
                        $sub->orWhere('faculte_id', $faculteId);
                    }
                });
            })
            ->with('batimentRelation')
            ->get();

        $teacherIdsDemandes = $demandes->pluck('teacherId')->filter()
            ->merge($demandes->flatMap(fn ($d) => $d->coEnseignantsIds ?? []))
            ->unique()
            ->values();

        $enseignants = Teacher::where('actif', true)
            ->where(function ($q) use ($faculteId, $teacherIdsDemandes) {
                if ($teacherIdsDemandes->isNotEmpty()) {
                    $q->whereIn('id', $teacherIdsDemandes);
                }
                if ($faculteId !== null) {
                    $q->orWhere(function ($sub) use ($faculteId) {
                        $sub->whereNull('departement_id')
                            ->orWhereHas('departement', fn ($d) => $d->where('faculte_id', $faculteId));
                    });
                } else {
                    $q->orWhereNotNull('id');
                }
            })
            ->get();

        if ($enseignants->isEmpty()) {
            $enseignants = Teacher::where('actif', true)->get();
        }

        // 4. Créneaux existants conservés (si non écrasement)
        $creneauxFixes = $ecraserBrouillon ? collect() : $slotsInitiaux;

        // 5. Ordonnancement MRV (Minimum Remaining Values)
        $domainesInitiaux = [];
        foreach ($demandes as $demande) {
            $domainesInitiaux[$demande->id] = $this->calculerDomaineCandidat(
                $demande,
                $schedule,
                $plagesHoraires,
                $salles,
                $enseignants,
                $creneauxFixes
            );
        }

        // Tri des séances par taille croissante de domaine initial (MRV) et priorité métier décroissante (Phase C - R2)
        $demandesOrdonnees = $demandes->sort(function (SeanceDemande $a, SeanceDemande $b) use ($domainesInitiaux) {
            $tailleA = count($domainesInitiaux[$a->id]);
            $tailleB = count($domainesInitiaux[$b->id]);

            if ($tailleA !== $tailleB) {
                return $tailleA <=> $tailleB;
            }

            return $b->priorite <=> $a->priorite;
        })->values();

        // 6. Résolution par Backtracking avec Forward Checking et bornes de ressources
        $iterations = 0;
        $solutions = [];
        $erreurs = [];

        $succes = $this->resoudreCSP(
            index: 0,
            demandes: $demandesOrdonnees,
            domaines: $domainesInitiaux,
            schedule: $schedule,
            creneauxFixes: $creneauxFixes,
            affectationsEnCours: [],
            iterations: $iterations,
            iterationMax: $iterationMax,
            heureFinMax: $debutChrono + $timeoutSecondes,
            solutionsSortie: $solutions,
            erreursSortie: $erreurs,
            contexte: $contexte
        );

        $dureeMs = round((microtime(true) - $debutChrono) * 1000, 2);
        $nbPlacees = count($solutions);
        $nbTotal = $demandes->count();

        $statut = match (true) {
            $nbPlacees === $nbTotal => TimetableGeneration::STATUT_SUCCES,
            $nbPlacees > 0 => TimetableGeneration::STATUT_PARTIEL,
            default => TimetableGeneration::STATUT_ECHEC,
        };

        // 7. Persistance en base de données si dry-run false
        $scoreQualite = null;
        $statistiques = [];

        if (! $dryRun && $nbPlacees > 0) {
            DB::transaction(function () use ($schedule, $ecraserBrouillon, $solutions, $auteurId) {
                if ($ecraserBrouillon) {
                    $schedule->slots()->where('status', '!=', 'cancelled')->delete();
                }

                foreach ($solutions as $sol) {
                    $slot = ScheduleSlot::create([
                        'schedule_id' => $schedule->id,
                        'matiere_id' => $sol['seance']->matiereId,
                        'enseignement_id' => $sol['seance']->enseignementId,
                        'teacher_id' => $sol['teacher_id'],
                        'room_id' => $sol['room_id'],
                        'day_of_week' => $sol['jour'],
                        'start_time' => $sol['debut'],
                        'end_time' => $sol['fin'],
                        'status' => 'scheduled',
                        'type' => $sol['seance']->type,
                        'sous_groupe_id' => $sol['seance']->sousGroupeId,
                        'semaine_parite' => $sol['seance']->semaineParite,
                        'semaines_specifiques' => $sol['seance']->semainesSpecifiques,
                        'equipements_requis' => $sol['seance']->equipementsRequis,
                    ]);

                    if (! empty($sol['seance']->coEnseignantsIds)) {
                        $slot->coEnseignants()->sync($sol['seance']->coEnseignantsIds);
                    }

                    ScheduleChange::create([
                        'schedule_slot_id' => $slot->id,
                        'changed_by' => $auteurId,
                        'change_type' => 'created',
                        'new_values' => $slot->toArray(),
                        'reason' => 'Génération automatique d\'emploi du temps (Phase 4 CSP)',
                    ]);
                }
            });

            // Évaluation de l'audit et du score qualité sur le planning résultant
            $audit = $this->checker->auditerPlanning($schedule->fresh());
            $scoreQualite = $audit->scoreQualite();
            $statistiques = $audit->metriques();
        } else {
            // En simulation, on calcule le score prévisionnel à partir des créneaux simulés
            $auditSimule = $this->evaluerSimulation($schedule, $creneauxFixes, $solutions);
            $scoreQualite = $auditSimule->scoreQualite();
            $statistiques = $auditSimule->metriques();
        }

        // 8. Enregistrement de l'historique immuable de génération
        return TimetableGeneration::create([
            'schedule_id' => $schedule->id,
            'faculte_id' => $faculteId,
            'auteur_id' => $auteurId,
            'statut' => $statut,
            'nombre_seances_a_placer' => $nbTotal,
            'nombre_seances_placees' => $nbPlacees,
            'nombre_iterations' => $iterations,
            'duree_ms' => $dureeMs,
            'score_qualite' => $scoreQualite,
            'parametres' => [
                'dry_run' => $dryRun,
                'ecraser_brouillon' => $ecraserBrouillon,
                'iteration_max' => $iterationMax,
                'timeout_secondes' => $timeoutSecondes,
                // PHASE B : pondérations réellement appliquées (traçabilité).
                'ponderations' => $contexte['ponderations'],
                'preferences_souhaitees' => $contexte['compte_souhaits'],
            ],
            'statistiques' => $statistiques,
            'erreurs' => $erreurs,
            'instantané_avant' => $snapshotAvant,
        ]);
    }

    /**
     * Recherche récursive avec Backtracking chronologique et Forward Checking.
     *
     * @param  Collection<int, SeanceDemande>  $demandes
     * @param  array<string, array<int, array<string, mixed>>>  $domaines
     * @param  Collection<int, ScheduleSlot>  $creneauxFixes
     * @param  array<int, array<string, mixed>>  $affectationsEnCours
     * @param  array<int, array<string, mixed>>  $solutionsSortie
     * @param  array<string, string>  $erreursSortie
     * @param  array<string, mixed>  $contexte
     */
    private function resoudreCSP(
        int $index,
        Collection $demandes,
        array $domaines,
        Schedule $schedule,
        Collection $creneauxFixes,
        array $affectationsEnCours,
        int &$iterations,
        int $iterationMax,
        float $heureFinMax,
        array &$solutionsSortie,
        array &$erreursSortie,
        array $contexte
    ): bool {
        // Base de terminaison : toutes les séances sont placées !
        if ($index >= $demandes->count()) {
            $solutionsSortie = $affectationsEnCours;

            return true;
        }

        // Garde-fous de ressources (itérations ou timeout dépassé)
        if (++$iterations > $iterationMax || microtime(true) > $heureFinMax) {
            $solutionsSortie = $affectationsEnCours;

            return false;
        }

        /** @var SeanceDemande $seance */
        $seance = $demandes[$index];
        $candidats = $domaines[$seance->id] ?? [];

        if (empty($candidats)) {
            $erreursSortie[$seance->id] = "Aucun créneau ou ressource compatible pour la séance « {$seance->titre} ».";

            return false;
        }

        // Ordonnancement qualitatif des candidats (LCV : minimisation des pénalités souples)
        $candidatsTries = $this->ordonnerCandidatsParQualite($candidats, $seance, $affectationsEnCours, $creneauxFixes, $schedule, $contexte);

        foreach ($candidatsTries as $candidat) {
            // Vérification stricte des contraintes dures contre l'état courant
            if (! $this->estPlacementSansConflit($candidat, $seance, $schedule, $creneauxFixes, $affectationsEnCours)) {
                continue;
            }

            // Affectation provisoire
            $affectation = array_merge($candidat, ['seance' => $seance]);
            $nouvellesAffectations = array_merge($affectationsEnCours, [$affectation]);

            // Forward Checking : vérifier qu'aucune séance restante n'a son domaine réduit à vide
            if ($this->forwardCheckingValide($index + 1, $demandes, $domaines, $nouvellesAffectations, $schedule, $creneauxFixes)) {
                $succes = $this->resoudreCSP(
                    $index + 1,
                    $demandes,
                    $domaines,
                    $schedule,
                    $creneauxFixes,
                    $nouvellesAffectations,
                    $iterations,
                    $iterationMax,
                    $heureFinMax,
                    $solutionsSortie,
                    $erreursSortie,
                    $contexte
                );

                if ($succes) {
                    return true;
                }
            }
        }

        // Échec sur ce sous-arbre : mémorisation du meilleur résultat partiel
        if (count($affectationsEnCours) > count($solutionsSortie)) {
            $solutionsSortie = $affectationsEnCours;
        }

        return false;
    }

    /**
     * Calcule le domaine initial des triplets (créneau, salle, enseignant) valides pour une séance.
     *
     * @param  Collection<int, TimeSlot>  $plages
     * @param  Collection<int, Room>  $salles
     * @param  Collection<int, Teacher>  $enseignants
     * @param  Collection<int, ScheduleSlot>  $creneauxFixes
     * @return array<int, array<string, mixed>>
     */
    private function calculerDomaineCandidat(
        SeanceDemande $seance,
        Schedule $schedule,
        Collection $plages,
        Collection $salles,
        Collection $enseignants,
        Collection $creneauxFixes
    ): array {
        $domaine = [];
        $effectif = $this->effectif($schedule, $seance->sousGroupeId);
        $codeMatiere = $this->codeMatiere($seance->matiereId);

        // 1. Filtrage des salles compatibles
        $sallesCompatibles = $salles->filter(function (Room $room) use ($seance, $effectif) {
            if ($seance->roomId !== null && $room->id !== $seance->roomId) {
                return false;
            }
            if ($room->capacite !== null && $room->capacite < $effectif) {
                return false;
            }
            if (! empty($seance->equipementsRequis)) {
                $fixes = $room->equipements_fixes ?? [];
                foreach ($seance->equipementsRequis as $equip) {
                    if (! in_array($equip, $fixes, true)) {
                        return false;
                    }
                }
            }

            return true;
        });

        // 2. Filtrage des enseignants qualifiés (qualifications exprimées en
        // CODE matière — cohérent avec la contrainte dure `conflitsQualifications`).
        $enseignantsCompatibles = $enseignants->filter(function (Teacher $t) use ($seance, $codeMatiere) {
            if ($seance->teacherId !== null) {
                return $t->id === $seance->teacherId;
            }

            return $codeMatiere === null || $t->estQualifiePour($codeMatiere);
        });

        if ($enseignantsCompatibles->isEmpty() && $seance->teacherId === null) {
            // Si aucun prof explicitement qualifié, autoriser tous les enseignants actifs du département/faculté
            $enseignantsCompatibles = $enseignants;
        }

        // 3. Combinaison (Plage horaire × Salle × Enseignant)
        foreach ($plages as $plage) {
            $debut = substr((string) $plage->start_time, 0, 8);
            $fin = substr((string) $plage->end_time, 0, 8);
            $jour = $plage->day_of_week;

            // Pause obligatoire
            $pauses = $this->calendarService->verifierPausesObligatoires($debut, $fin, $jour, $schedule->groupe?->faculte_id);
            if (! empty($pauses)) {
                continue;
            }

            foreach ($sallesCompatibles as $salle) {
                // Heures d'ouverture de salle
                if (! $salle->estOuvertePlage($debut, $fin)) {
                    continue;
                }

                foreach ($enseignantsCompatibles as $ens) {
                    $domaine[] = [
                        'jour' => $jour,
                        'debut' => $debut,
                        'fin' => $fin,
                        'room_id' => $salle->id,
                        'room' => $salle,
                        'teacher_id' => $ens->id,
                        'teacher' => $ens,
                    ];
                }
            }
        }

        return $domaine;
    }

    /**
     * Vérifie si un placement candidat est exempt de conflit dur contre les créneaux déjà existants
     * ET contre les affectations déjà validées dans la génération courante.
     *
     * @param  array<string, mixed>  $candidat
     * @param  Collection<int, ScheduleSlot>  $creneauxFixes
     * @param  array<int, array<string, mixed>>  $affectationsEnCours
     */
    private function estPlacementSansConflit(
        array $candidat,
        SeanceDemande $seance,
        Schedule $schedule,
        Collection $creneauxFixes,
        array $affectationsEnCours
    ): bool {
        $jour = $candidat['jour'];
        $debut = $candidat['debut'];
        $fin = $candidat['fin'];
        $roomId = $candidat['room_id'];
        $teacherId = $candidat['teacher_id'];
        $parite = $seance->semaineParite;
        $semaines = $seance->semainesSpecifiques;

        // A. Vérification contre les créneaux déjà persistés en base (ou plannings concurrents)
        $conflits = $this->conflictService->verifier(
            $schedule->id,
            $jour,
            $debut,
            $fin,
            $teacherId,
            $roomId,
            null,
            null,
            $schedule,
            $parite,
            $semaines,
            $seance->sousGroupeId,
            $seance->equipementsRequis,
            $seance->coEnseignantsIds,
            $seance->matiereId
        );

        if (! empty($conflits)) {
            return false;
        }

        // B. Vérification contre les affectations de la résolution courante en cours
        foreach ($affectationsEnCours as $aff) {
            // Chevauchement temporel
            if ($aff['jour'] === $jour && $aff['debut'] < $fin && $debut < $aff['fin']) {
                /** @var SeanceDemande $affSeance */
                $affSeance = $aff['seance'];

                // Parité compatible (si les deux sont paires/impaires identiques ou l'un est 'toutes')
                $conflitSemaines = ($parite === 'toutes' || $affSeance->semaineParite === 'toutes' || $parite === $affSeance->semaineParite);
                if (! $conflitSemaines) {
                    continue;
                }

                // 1. Conflit de salle
                if ($aff['room_id'] === $roomId) {
                    return false;
                }

                // 2. Conflit d'enseignant
                if ($aff['teacher_id'] === $teacherId) {
                    return false;
                }
                if (! empty($seance->coEnseignantsIds) && in_array($aff['teacher_id'], $seance->coEnseignantsIds, true)) {
                    return false;
                }
                if (! empty($affSeance->coEnseignantsIds) && in_array($teacherId, $affSeance->coEnseignantsIds, true)) {
                    return false;
                }

                // 3. Conflit de groupe (même groupe entier, ou sous-groupe identique)
                if ($seance->sousGroupeId === null || $affSeance->sousGroupeId === null || $seance->sousGroupeId === $affSeance->sousGroupeId) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Forward Checking : vérifie que chaque séance non encore placée conserve au moins une option valide.
     *
     * @param  Collection<int, SeanceDemande>  $demandes
     * @param  array<string, array<int, array<string, mixed>>>  $domaines
     * @param  array<int, array<string, mixed>>  $nouvellesAffectations
     * @param  Collection<int, ScheduleSlot>  $creneauxFixes
     */
    private function forwardCheckingValide(
        int $prochainIndex,
        Collection $demandes,
        array $domaines,
        array $nouvellesAffectations,
        Schedule $schedule,
        Collection $creneauxFixes
    ): bool {
        for ($i = $prochainIndex; $i < $demandes->count(); $i++) {
            $futureSeance = $demandes[$i];
            $candidats = $domaines[$futureSeance->id] ?? [];
            $aAuMoinsUneOption = false;

            foreach ($candidats as $cand) {
                if ($this->estPlacementSansConflit($cand, $futureSeance, $schedule, $creneauxFixes, $nouvellesAffectations)) {
                    $aAuMoinsUneOption = true;
                    break;
                }
            }

            if (! $aAuMoinsUneOption) {
                return false; // Élagage immédiat (pruning)
            }
        }

        return true;
    }

    /**
     * Ordonne les candidats d'une séance (LCV) selon les pénalités souples
     * DÉCLARÉES — registre de contraintes, pondérations `planning.generation.*`
     * (Phase A) et plages `souhaitee`. Aucune requête SQL dans la boucle.
     *
     * @param  array<int, array<string, mixed>>  $candidats
     * @param  array<int, array<string, mixed>>  $affectationsEnCours
     * @param  Collection<int, ScheduleSlot>  $creneauxFixes
     * @param  array<string, mixed>  $contexte
     * @return array<int, array<string, mixed>>
     */
    private function ordonnerCandidatsParQualite(
        array $candidats,
        SeanceDemande $seance,
        array $affectationsEnCours,
        Collection $creneauxFixes,
        Schedule $schedule,
        array $contexte
    ): array {
        $evalues = [];

        foreach ($candidats as $cand) {
            $evalues[] = [
                'candidat' => $cand,
                'score' => $this->penalitesCandidat($cand, $seance, $affectationsEnCours, $creneauxFixes, $schedule, $contexte),
            ];
        }

        usort($evalues, fn ($a, $b) => $a['score'] <=> $b['score']);

        return array_column($evalues, 'candidat');
    }

    /**
     * Somme algébrique des pénalités souples d'un candidat (un bonus est
     * négatif). Tout provient du contexte préchargé : aucune requête SQL,
     * aucune écriture — utilisable dans les boucles chaudes du backtracking.
     *
     * @param  array<string, mixed>  $candidat
     * @param  array<int, array<string, mixed>>  $affectationsEnCours
     * @param  Collection<int, ScheduleSlot>  $creneauxFixes
     * @param  array<string, mixed>  $contexte
     */
    private function penalitesCandidat(
        array $candidat,
        SeanceDemande $seance,
        array $affectationsEnCours,
        Collection $creneauxFixes,
        Schedule $schedule,
        array $contexte
    ): float {
        $jour = (string) $candidat['jour'];
        $debut = (string) $candidat['debut'];
        $fin = (string) $candidat['fin'];
        $contraintes = $contexte['contraintes'];
        $facteurs = $contexte['facteurs'];
        $penalite = 0.0;

        // Vue normalisée du jour : séances du planning + affectations en cours
        // + le candidat lui-même (marqué pour être retrouvé après tri).
        $jourSessions = $this->sessionsDuJour($jour, $affectationsEnCours, $creneauxFixes);
        $jourSessions[] = [
            'debut' => $debut,
            'fin' => $fin,
            'teacher_ids' => $this->enseignantsDe($seance, $candidat),
            'room_id' => $candidat['room_id'] !== null ? (int) $candidat['room_id'] : null,
            'batiment_id' => $candidat['room']?->batiment_id !== null ? (int) $candidat['room']->batiment_id : null,
            'matiere_id' => $seance->matiereId,
            'minutes' => $this->minutesEntre($debut, $fin),
            'candidat' => true,
        ];
        usort($jourSessions, fn ($a, $b) => strcmp((string) $a['debut'], (string) $b['debut']));

        $indexCandidat = 0;
        foreach ($jourSessions as $i => $s) {
            if (! empty($s['candidat'])) {
                $indexCandidat = $i;
            }
        }

        $precedent = $indexCandidat > 0 ? $jourSessions[$indexCandidat - 1] : null;
        $suivant = $indexCandidat < count($jourSessions) - 1 ? $jourSessions[$indexCandidat + 1] : null;

        // a) Trous étudiants : battements inutiles autour de la séance.
        if ($contraintes['trous']['actif'] && $facteurs['trous'] > 0) {
            if ($precedent !== null) {
                $penalite += $this->penaliteBattement(
                    $this->minutesEntre((string) $precedent['fin'], $debut),
                    $contraintes['trous'],
                    $facteurs['trous']
                );
            }
            if ($suivant !== null) {
                $penalite += $this->penaliteBattement(
                    $this->minutesEntre($fin, (string) $suivant['debut']),
                    $contraintes['trous'],
                    $facteurs['trous']
                );
            }
        }

        // b) Trous enseignants : battements de l'enseignant (et co-enseignants) ce jour.
        if ($contraintes['trous_enseignants']['actif'] && $facteurs['trous'] > 0) {
            $enseignantsCandidat = $this->enseignantsDe($seance, $candidat);
            $seancesEnseignant = array_values(array_filter(
                $jourSessions,
                fn ($s) => array_intersect($s['teacher_ids'], $enseignantsCandidat) !== []
            ));

            $position = 0;
            foreach ($seancesEnseignant as $i => $s) {
                if (! empty($s['candidat'])) {
                    $position = $i;
                }
            }

            if ($position > 0) {
                $penalite += $this->penaliteBattement(
                    $this->minutesEntre((string) $seancesEnseignant[$position - 1]['fin'], $debut),
                    $contraintes['trous_enseignants'],
                    $facteurs['trous']
                );
            }
            if ($position < count($seancesEnseignant) - 1) {
                $penalite += $this->penaliteBattement(
                    $this->minutesEntre($fin, (string) $seancesEnseignant[$position + 1]['debut']),
                    $contraintes['trous_enseignants'],
                    $facteurs['trous']
                );
            }
        }

        // c) Séquence continue : plafond de minutes sans pause suffisante
        // (le paramètre borné `duree_max_sequence_minutes` et le registre
        // sont cumulés de façon conservatrice : le plus strict gagne).
        if ($contraintes['sequence']['actif']) {
            $maxMinutes = (int) min(
                (int) $contexte['duree_max_sequence_minutes'],
                round(((float) ($contraintes['sequence']['params']['max_heures'] ?? 4)) * 60)
            );

            $bloc = $this->minutesBlocContinu($jourSessions, $indexCandidat);
            if ($maxMinutes > 0 && $bloc > $maxMinutes) {
                $cout = (float) ($contraintes['sequence']['params']['penalite_par_heure_supp'] ?? 10);
                $poids = (float) $contraintes['sequence']['poids'] / 10;
                $penalite += (($bloc - $maxMinutes) / 60) * $cout * $poids;
            }
        }

        // d) Équilibre inter-jours : pénalise un jour plus chargé que la moyenne.
        if ($contraintes['equilibre']['actif'] && $facteurs['equilibre'] > 0) {
            $joursOuverts = max(1, (int) $contexte['jours_domaine']);
            $chargeJour = array_sum(array_map(fn ($s) => (int) $s['minutes'], $jourSessions));
            $chargeTotale = $this->chargeHebdoMinutes($affectationsEnCours, $creneauxFixes)
                + $this->minutesEntre($debut, $fin);
            $moyenne = $chargeTotale / $joursOuverts;

            if ($chargeJour > $moyenne) {
                $cout = (float) ($contraintes['equilibre']['params']['penalite_par_heure_ecart'] ?? 5);
                $poids = (float) $contraintes['equilibre']['poids'] / 10;
                $penalite += (($chargeJour - $moyenne) / 60) * $cout * $poids * $facteurs['equilibre'];
            }
        }

        // e) Préférences `souhaitee` (PHASE A R2) : bonus dans une plage
        // souhaitée, pénalité en dehors — par enseignant, salle et groupe.
        if ($contraintes['preferences']['actif']) {
            $cout = (float) ($contraintes['preferences']['params']['penalite_cours_hors_souhait'] ?? 8);
            $poids = (float) $contraintes['preferences']['poids'] / 10;

            foreach ($this->souhaitsConcernes($seance, $candidat, $schedule, $contexte) as $plages) {
                $plagesJour = $plages->filter(fn (ScheduleAvailability $p) => $p->day_of_week === $jour);
                if ($plagesJour->isEmpty()) {
                    continue;
                }

                $correspond = $plagesJour->contains(fn (ScheduleAvailability $p) => $p->chevauche($debut, $fin));
                $penalite += $correspond ? -($cout * $poids) : ($cout * $poids);
            }
        }

        // f) Salles surdimensionnées (paramètres du registre).
        if ($contraintes['surdimensionnement']['actif']) {
            $room = $candidat['room'] ?? null;
            $effectif = $this->effectif($schedule, $seance->sousGroupeId);
            $seuilMin = (int) ($contraintes['surdimensionnement']['params']['seuil_capacite_min'] ?? 60);
            $ratio = (float) ($contraintes['surdimensionnement']['params']['ratio_seuil'] ?? 2.5);
            $cout = (float) ($contraintes['surdimensionnement']['params']['penalite_surdimensionnement'] ?? 5);

            if ($room !== null && $room->capacite !== null && $room->capacite >= $seuilMin
                && $effectif !== null && $effectif > 0 && $room->capacite > ($effectif * $ratio)) {
                $penalite += $cout * ((float) $contraintes['surdimensionnement']['poids'] / 10);
            }
        }

        // g) Transit inter-bâtiments : battement insuffisant autour du candidat.
        if ($contraintes['transit']['actif'] && ($candidat['room']?->batiment_id ?? null) !== null) {
            $tempsRequis = (int) ($contraintes['transit']['params']['temps_transit_min'] ?? 15);
            $cout = (float) ($contraintes['transit']['params']['penalite_transit_insuffisant'] ?? 15);
            $poids = (float) $contraintes['transit']['poids'] / 10;

            foreach ([$precedent, $suivant] as $voisin) {
                if ($voisin === null || ($voisin['batiment_id'] ?? null) === null) {
                    continue;
                }
                if ((int) $voisin['batiment_id'] === (int) $candidat['room']->batiment_id) {
                    continue;
                }

                $battement = $this->minutesEntre((string) $voisin['fin'], $debut);
                if ((string) $voisin['debut'] >= $fin) {
                    $battement = $this->minutesEntre($fin, (string) $voisin['debut']);
                }

                if ($battement < $tempsRequis) {
                    $penalite += $cout * $poids;
                }
            }
        }

        // h) Continuité pédagogique (`poids_continue`) : regrouper les séances
        // d'une même matière le même jour, idéalement enchaînées.
        if ($facteurs['continuite'] > 0) {
            $matiereMemeJour = false;
            $matiereChainee = false;

            foreach ($jourSessions as $i => $s) {
                if ($i === $indexCandidat || (int) $s['matiere_id'] !== $seance->matiereId) {
                    continue;
                }
                $matiereMemeJour = true;
                if ($this->sontChainees($jourSessions, $indexCandidat, $i)) {
                    $matiereChainee = true;
                }
            }

            if ($matiereChainee) {
                $penalite -= 3.0 * $facteurs['continuite'];
            } elseif ($matiereMemeJour) {
                $penalite += 1.5 * $facteurs['continuite'];
            }
        }

        return $penalite;
    }

    /**
     * Pénalité d'un battement (minutes) selon les paramètres d'une contrainte
     * de trous : seuil de pause gratuit puis coût par tranche de 30 minutes.
     *
     * @param  array<string, mixed>  $contrainte
     */
    private function penaliteBattement(int $battement, array $contrainte, float $facteur): float
    {
        if ($battement <= 0) {
            return 0.0;
        }

        $seuil = (int) ($contrainte['params']['seuil_pause_minutes'] ?? 30);
        if ($battement <= $seuil) {
            return 0.0;
        }

        $cout = (float) ($contrainte['params']['penalite_par_30m'] ?? 5);
        $poids = (float) $contrainte['poids'] / 10;

        return ceil(($battement - $seuil) / 30) * $cout * $poids * $facteur;
    }

    /**
     * Plages `souhaitee` des ressources concernées par un candidat
     * (enseignant et co-enseignants, salle, groupe du planning).
     *
     * @param  array<string, mixed>  $candidat
     * @param  array<string, mixed>  $contexte
     * @return array<int, Collection<int, ScheduleAvailability>>
     */
    private function souhaitsConcernes(SeanceDemande $seance, array $candidat, Schedule $schedule, array $contexte): array
    {
        $souhaits = $contexte['souhaits'];
        $listes = [];

        foreach ($this->enseignantsDe($seance, $candidat) as $teacherId) {
            if ($souhaits['enseignants']->has($teacherId)) {
                $listes[] = $souhaits['enseignants']->get($teacherId);
            }
        }

        $roomId = $candidat['room_id'] !== null ? (int) $candidat['room_id'] : null;
        if ($roomId !== null && $souhaits['salles']->has($roomId)) {
            $listes[] = $souhaits['salles']->get($roomId);
        }

        if ($schedule->groupe_id !== null && $souhaits['groupes']->has((int) $schedule->groupe_id)) {
            $listes[] = $souhaits['groupes']->get((int) $schedule->groupe_id);
        }

        return $listes;
    }

    /**
     * Séances déjà positionnées un jour donné (planning existant +
     * affectations de la résolution courante) — forme normalisée.
     *
     * @param  array<int, array<string, mixed>>  $affectationsEnCours
     * @param  Collection<int, ScheduleSlot>  $creneauxFixes
     * @return array<int, array<string, mixed>>
     */
    private function sessionsDuJour(string $jour, array $affectationsEnCours, Collection $creneauxFixes): array
    {
        $sessions = [];

        foreach ($creneauxFixes as $slot) {
            if ((string) $slot->day_of_week !== $jour) {
                continue;
            }

            $coEnseignants = $slot->relationLoaded('coEnseignants')
                ? $slot->coEnseignants->pluck('id')->map(fn ($id) => (int) $id)->all()
                : [];

            $sessions[] = [
                'debut' => substr((string) $slot->start_time, 0, 8),
                'fin' => substr((string) $slot->end_time, 0, 8),
                'teacher_ids' => array_values(array_filter(array_merge(
                    $slot->teacher_id !== null ? [(int) $slot->teacher_id] : [],
                    $coEnseignants
                ))),
                'room_id' => $slot->room_id !== null ? (int) $slot->room_id : null,
                'batiment_id' => $slot->room?->batiment_id !== null ? (int) $slot->room->batiment_id : null,
                'matiere_id' => $slot->matiere_id !== null ? (int) $slot->matiere_id : null,
                'minutes' => $this->minutesEntre((string) $slot->start_time, (string) $slot->end_time),
            ];
        }

        foreach ($affectationsEnCours as $affectation) {
            if (! isset($affectation['seance']) || (string) $affectation['jour'] !== $jour) {
                continue;
            }

            /** @var SeanceDemande $seancePlacee */
            $seancePlacee = $affectation['seance'];
            $room = $affectation['room'] ?? null;

            $sessions[] = [
                'debut' => (string) $affectation['debut'],
                'fin' => (string) $affectation['fin'],
                'teacher_ids' => $this->enseignantsDe($seancePlacee, $affectation),
                'room_id' => $affectation['room_id'] !== null ? (int) $affectation['room_id'] : null,
                'batiment_id' => $room?->batiment_id !== null ? (int) $room->batiment_id : null,
                'matiere_id' => $seancePlacee->matiereId,
                'minutes' => $this->minutesEntre((string) $affectation['debut'], (string) $affectation['fin']),
            ];
        }

        return $sessions;
    }

    /**
     * Enseignants concernés par une affectation (titulaire + co-enseignants).
     *
     * @param  array<string, mixed>  $affectation
     * @return array<int, int>
     */
    private function enseignantsDe(SeanceDemande $seance, array $affectation): array
    {
        $titulaire = isset($affectation['teacher_id']) && $affectation['teacher_id'] !== null
            ? [(int) $affectation['teacher_id']]
            : [];

        return array_values(array_unique(array_filter(
            array_merge($titulaire, array_map('intval', $seance->coEnseignantsIds)),
            fn ($id) => $id !== null
        )));
    }

    /**
     * Minutes de la séquence continue contenant l'index donné (chaînage :
     * battement < 20 min — même convention que l'audit indépendant).
     *
     * @param  array<int, array<string, mixed>>  $sessions
     */
    private function minutesBlocContinu(array $sessions, int $index): int
    {
        $debut = $index;
        $fin = $index;

        while ($debut > 0 && $this->minutesEntre((string) $sessions[$debut - 1]['fin'], (string) $sessions[$debut]['debut']) < self::SEUIL_ENCHAINEMENT_MINUTES) {
            $debut--;
        }
        while ($fin < count($sessions) - 1 && $this->minutesEntre((string) $sessions[$fin]['fin'], (string) $sessions[$fin + 1]['debut']) < self::SEUIL_ENCHAINEMENT_MINUTES) {
            $fin++;
        }

        $total = 0;
        for ($k = $debut; $k <= $fin; $k++) {
            $total += (int) $sessions[$k]['minutes'];
        }

        return $total;
    }

    /**
     * Deux séances sont-elles chaînées (tous les battements intermédiaires
     * restent sous le seuil d'enchaînement) ?
     *
     * @param  array<int, array<string, mixed>>  $sessions
     */
    private function sontChainees(array $sessions, int $a, int $b): bool
    {
        $i = min($a, $b);
        $j = max($a, $b);

        for ($k = $i; $k < $j; $k++) {
            if ($this->minutesEntre((string) $sessions[$k]['fin'], (string) $sessions[$k + 1]['debut']) >= self::SEUIL_ENCHAINEMENT_MINUTES) {
                return false;
            }
        }

        return true;
    }

    /**
     * Charge hebdomadaire déjà positionnée (minutes) : planning + affectations.
     *
     * @param  array<int, array<string, mixed>>  $affectationsEnCours
     * @param  Collection<int, ScheduleSlot>  $creneauxFixes
     */
    private function chargeHebdoMinutes(array $affectationsEnCours, Collection $creneauxFixes): int
    {
        $total = 0;

        foreach ($creneauxFixes as $slot) {
            $total += $this->minutesEntre((string) $slot->start_time, (string) $slot->end_time);
        }

        foreach ($affectationsEnCours as $affectation) {
            $total += $this->minutesEntre((string) $affectation['debut'], (string) $affectation['fin']);
        }

        return $total;
    }

    private function minutesEntre(string $debut, string $fin): int
    {
        $d = strtotime("1970-01-01 {$debut}");
        $f = strtotime("1970-01-01 {$fin}");

        if ($d === false || $f === false || $f < $d) {
            return 0;
        }

        return (int) round(($f - $d) / 60);
    }

    /**
     * PHASE B — contexte qualitatif d'une génération : contraintes souples
     * (activation, poids, paramètres du registre), pondérations bornées des
     * paramètres `planning.generation.*` et plages `souhaitee` déclarées.
     * Une seule passe SQL, mémorisée pour la durée de la génération.
     *
     * @param  Collection<int, TimeSlot>  $plagesHoraires
     * @return array<string, mixed>
     */
    private function construireContexteQualite(Schedule $schedule, ?int $faculteId, Collection $plagesHoraires): array
    {
        // Pondérations : valeurs bornées à la lecture (Phase A).
        $ponderations = [
            'poids_trous' => $this->settings->poidsTrous(),
            'poids_equilibre_jours' => $this->settings->poidsEquilibreJours(),
            'poids_continuite' => $this->settings->poidsContinuite(),
            'duree_max_sequence_minutes' => $this->settings->dureeMaxSequenceMinutes(),
        ];

        // Clé de fraîcheur : tout ce qui peut changer le résultat sans
        // changer de planning (paramètres, registre, plages déclarées, jours).
        // Un cache périmé fausserait une seconde génération dans le même
        // processus (tests, workers) — même logique que la version de cache
        // de l'explorateur (Phase A).
        $cle = implode('|', [
            $schedule->id,
            $faculteId ?? 'toutes',
            implode(',', $ponderations),
            (string) ScheduleAvailability::query()->where('type', 'souhaitee')->max('updated_at'),
            (string) TimetableConstraint::query()->max('updated_at'),
            $plagesHoraires->pluck('day_of_week')->unique()->implode(','),
        ]);

        if (isset($this->contextesQualite[$cle])) {
            return $this->contextesQualite[$cle];
        }

        // 1. Registre des contraintes : une requête, lecture indexée en mémoire.
        $registre = $this->registry->obtenirContraintes($faculteId);
        $defauts = $this->registry->definitionsParDefaut();
        $contraintes = [];

        foreach ($this->aliasContraintesSouples() as $alias => $code) {
            $contrainte = $registre->get($code);
            $contraintes[$alias] = [
                'actif' => (bool) ($contrainte?->actif ?? true),
                'poids' => max(0, min(100, (int) ($contrainte?->poids ?? 10))),
                'params' => array_merge($defauts[$code]['parametres'] ?? [], $contrainte?->parametres ?? []),
            ];
        }

        // 2. Plages souhaitées : portée année du planning + fenêtre effective
        // (mêmes règles conservatrices que les indisponibilités — Phase A).
        $souhaits = ScheduleAvailability::query()
            ->where('type', 'souhaitee')
            ->where(function (Builder $q) use ($schedule) {
                $q->whereNull('annee_universitaire_id');
                if ($schedule->annee_universitaire_id !== null) {
                    $q->orWhere('annee_universitaire_id', $schedule->annee_universitaire_id);
                }
            })
            ->get()
            ->filter(fn (ScheduleAvailability $plage) => $plage->couvrePeriode(
                $schedule->starts_at?->format('Y-m-d'),
                $schedule->ends_at?->format('Y-m-d'),
            ));

        // 3. Assemblage du contexte (pondérations déjà bornées ci-dessus).
        $contexte = [
            'contraintes' => $contraintes,
            'ponderations' => $ponderations,
            'souhaits' => [
                'enseignants' => $souhaits->whereNotNull('teacher_id')->groupBy('teacher_id'),
                'salles' => $souhaits->whereNotNull('room_id')->groupBy('room_id'),
                'groupes' => $souhaits->whereNotNull('group_id')->groupBy('group_id'),
            ],
            'compte_souhaits' => [
                'enseignants' => $souhaits->whereNotNull('teacher_id')->pluck('teacher_id')->unique()->count(),
                'salles' => $souhaits->whereNotNull('room_id')->pluck('room_id')->unique()->count(),
                'groupes' => $souhaits->whereNotNull('group_id')->pluck('group_id')->unique()->count(),
            ],
            'duree_max_sequence_minutes' => $ponderations['duree_max_sequence_minutes'],
            'jours_domaine' => max(1, $plagesHoraires->pluck('day_of_week')->unique()->count()),
        ];

        // Facteurs d'échelle : les paramètres Phase A sont ajustés AUTOUR de
        // leurs défauts documentés (trous 5, équilibre 5, continuité 3) — au
        // défaut, le registre reste seul maître ; à 0, l'axe est neutralisé.
        $contexte['facteurs'] = [
            'trous' => $ponderations['poids_trous'] / 5,
            'equilibre' => $ponderations['poids_equilibre_jours'] / 5,
            'continuite' => $ponderations['poids_continuite'] / 3,
        ];

        return $this->contextesQualite[$cle] = $contexte;
    }

    /**
     * Alias internes du moteur → codes canoniques du registre de contraintes.
     *
     * @return array<string, string>
     */
    private function aliasContraintesSouples(): array
    {
        return [
            'trous' => TimetableConstraint::SOFT_STUDENT_IDLE_GAPS,
            'trous_enseignants' => TimetableConstraint::SOFT_TEACHER_IDLE_GAPS,
            'sequence' => TimetableConstraint::SOFT_MAX_CONSECUTIVE_HOURS,
            'preferences' => TimetableConstraint::SOFT_TEACHER_PREFERENCE,
            'surdimensionnement' => TimetableConstraint::SOFT_ROOM_OVERSIZED,
            'transit' => TimetableConstraint::SOFT_BUILDING_TRANSIT,
            'equilibre' => TimetableConstraint::SOFT_DAILY_EQUILIBRIUM,
        ];
    }

    /**
     * Effectif résolu, mémoïsé : aucun COUNT répété dans les boucles chaudes.
     */
    private function effectif(Schedule $schedule, ?int $sousGroupeId): ?int
    {
        $cle = $schedule->id.':'.($sousGroupeId ?? 'groupe');

        return $this->effectifsMemoises[$cle] ??= $this->conflictService->resoudreEffectif($schedule, null, $sousGroupeId);
    }

    /**
     * Code de la matière, mémoïsé : les qualifications enseignantes sont
     * exprimées en codes (cohérent avec la contrainte dure de qualification).
     */
    private function codeMatiere(int $matiereId): ?string
    {
        if (! array_key_exists($matiereId, $this->codesMatieres)) {
            $this->codesMatieres[$matiereId] = Matiere::query()->whereKey($matiereId)->value('code');
        }

        return $this->codesMatieres[$matiereId];
    }

    /**
     * PHASE B — la génération n'écrit jamais dans un planning publié,
     * verrouillé ou archivé (seule la simulation reste possible).
     */
    private function verifierPlanningModifiable(Schedule $schedule): void
    {
        if (in_array((string) $schedule->statut, self::STATUTS_NON_MODIFIABLES, true)) {
            throw new DomainException(
                "Génération refusée : le planning « {$schedule->nom} » est en statut « {$schedule->statut} ». "
                .'Dupliquez-le ou repassez-le en brouillon — aucune publication automatique n\'a lieu.'
            );
        }
    }

    /**
     * Bornage du garde-fou anti-boucle : jamais plus que le maximum déclaré
     * (`planning.generation.iteration_max`), jamais moins de 100 itérations.
     */
    private function bornerIterations(int $iterations): int
    {
        return max(100, min($this->settings->iterationMax(), $iterations));
    }

    /** Bornage du budget temps : 1 s à 60 s. */
    private function bornerTimeoutSecondes(int $secondes): int
    {
        return max(1, min(60, $secondes));
    }

    /**
     * Évalue un rapport d'audit simulé en mémoire à partir des séances planifiées fictives.
     *
     * @param  Collection<int, ScheduleSlot>  $creneauxFixes
     * @param  array<int, array<string, mixed>>  $solutions
     */
    private function evaluerSimulation(Schedule $schedule, Collection $creneauxFixes, array $solutions): ScheduleAuditReport
    {
        $slotsVirtuels = collect($creneauxFixes);

        foreach ($solutions as $sol) {
            $virtuel = new ScheduleSlot([
                'schedule_id' => $schedule->id,
                'matiere_id' => $sol['seance']->matiereId,
                'teacher_id' => $sol['teacher_id'],
                'room_id' => $sol['room_id'],
                'day_of_week' => $sol['jour'],
                'start_time' => $sol['debut'],
                'end_time' => $sol['fin'],
                'status' => 'scheduled',
                'type' => $sol['seance']->type,
                'sous_groupe_id' => $sol['seance']->sousGroupeId,
                'semaine_parite' => $sol['seance']->semaineParite,
            ]);
            $virtuel->setRelation('matiere', Matiere::find($sol['seance']->matiereId));
            $virtuel->setRelation('room', $sol['room']);
            $virtuel->setRelation('teacher', $sol['teacher']);
            $virtuel->setRelation('coEnseignants', collect());
            $slotsVirtuels->push($virtuel);
        }

        return $this->checker->auditerCreneaux($slotsVirtuels, $schedule->groupe?->faculte_id, $schedule);
    }

    /**
     * Résout la grille des plages horaires standards disponibles.
     *
     * @param  array<int, string>|null  $joursAutorises
     * @return Collection<int, TimeSlot>
     */
    private function resoudrePlagesHoraires(?int $faculteId, ?array $joursAutorises): Collection
    {
        $jours = $joursAutorises ?? ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        $enBase = TimeSlot::query()
            ->actif()
            ->cours()
            ->pourFaculte($faculteId)
            ->get();

        if ($enBase->isNotEmpty()) {
            return $enBase->filter(fn ($ts) => in_array($ts->day_of_week, $jours, true));
        }

        // Grille standard de référence (08:30-10:00, 10:15-11:45, 12:00-13:30, 14:00-15:30, 15:45-17:15)
        $tranches = [
            ['debut' => '08:30:00', 'fin' => '10:00:00'],
            ['debut' => '10:15:00', 'fin' => '11:45:00'],
            ['debut' => '12:00:00', 'fin' => '13:30:00'],
            ['debut' => '14:00:00', 'fin' => '15:30:00'],
            ['debut' => '15:45:00', 'fin' => '17:15:00'],
        ];

        $virtuels = collect();
        foreach ($jours as $j) {
            foreach ($tranches as $idx => $t) {
                $virtuels->push(new TimeSlot([
                    'faculte_id' => $faculteId,
                    'code' => "SLOT_{$j}_{$idx}",
                    'nom' => "Créneau standard {$t['debut']}–{$t['fin']}",
                    'day_of_week' => $j,
                    'start_time' => $t['debut'],
                    'end_time' => $t['fin'],
                    'type' => 'course',
                    'is_mandatory_break' => false,
                    'actif' => true,
                ]));
            }
        }

        return $virtuels;
    }
}
