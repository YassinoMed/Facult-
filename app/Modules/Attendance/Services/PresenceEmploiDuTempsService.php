<?php

namespace App\Modules\Attendance\Services;

use App\Models\AnneeUniversitaire;
use App\Models\IntegrationLog;
use App\Modules\Attendance\Models\TeacherPointage;
use App\Modules\Attendance\Models\TeacherPresence;
use App\Modules\Schedule\Models\ScheduleException;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Service de rapprochement intelligent des présences des enseignants (§1-§8).
 *
 * Compare les séances planifiées issues de l'emploi du temps (ScheduleSlot + ScheduleException)
 * avec les pointages réels des enseignants (TeacherPointage), applique les règles horaires
 * déterministes et produit un diagnostic explicable.
 *
 * Principes cardinaux :
 * - Fail-soft par conception : aucune absence définitive n'est automatiquement validée ;
 * - Distinction stricte : pointage manquant ≠ absence réelle ≠ séance annulée ;
 * - Conservation intégrale des pointages orphelins (aucun pointage supprimé silencieusement) ;
 * - Traçabilité et audit des validations et corrections manuelles.
 */
class PresenceEmploiDuTempsService
{
    public const TOLERANCE_PAR_DEFAUT = 15; // minutes

    public const FENETRE_PAR_DEFAUT = 60; // minutes de part et d'autre de la séance

    /**
     * Récupère et rapproche l'ensemble des séances d'une date donnée.
     *
     * @param  array<string, mixed>  $filtres
     * @return Collection<int, array<string, mixed>>
     */
    public function seancesDuJour(
        string $date,
        ?int $faculteId = null,
        array $filtres = [],
        int $toleranceMinutes = self::TOLERANCE_PAR_DEFAUT,
        int $fenetreMinutes = self::FENETRE_PAR_DEFAUT
    ): Collection {
        $jourCarbone = Carbon::parse($date);
        $dateNormalisee = $jourCarbone->format('Y-m-d');
        $jourSemaine = strtolower($jourCarbone->locale('en')->isoFormat('dddd'));

        // Année universitaire couvrant la date : seules les séances des EDT de
        // l'année académique en cours de validité sont « prévues ». Sans ce
        // filtre, les emplois du temps d'années passées (actif=true) généreraient
        // des séances fantômes et de fausses absences à vérifier. Aucune année
        // couvrante = aucune séance prévue ce jour-là (constat explicite).
        $anneeCouvranteId = AnneeUniversitaire::query()
            ->whereDate('date_debut', '<=', $dateNormalisee)
            ->whereDate('date_fin', '>=', $dateNormalisee)
            ->value('id');

        if ($anneeCouvranteId === null) {
            return collect();
        }

        // 1. Récupération des créneaux hebdomadaires récurrents avec eager-loading complet (zéro N+1)
        $query = ScheduleSlot::with([
            'schedule.groupe.faculte',
            'schedule.anneeUniversitaire',
            'matiere',
            'teacher.departement.faculte',
            'room.faculte',
            'exceptions' => fn ($q) => $q->whereDate('exception_date', $dateNormalisee)->with(['nouvelEnseignant.departement.faculte', 'nouvelleSalle.faculte']),
            'presences' => fn ($q) => $q->whereDate('date', $dateNormalisee)->with('validePar'),
        ])
            ->where('day_of_week', $jourSemaine)
            ->whereHas('schedule', function ($q) use ($anneeCouvranteId) {
                $q->where('actif', true)
                    ->where('annee_universitaire_id', $anneeCouvranteId);
            });

        // Isolation par faculté sur le périmètre de la séance (groupe ou enseignant)
        if ($faculteId !== null) {
            $query->where(function ($sub) use ($faculteId) {
                $sub->whereHas('schedule.groupe', fn ($g) => $g->where('faculte_id', $faculteId))
                    ->orWhereHas('teacher.departement', fn ($d) => $d->where('faculte_id', $faculteId));
            });
        }

        // Filtres optionnels
        if (! empty($filtres['groupe_id'])) {
            $query->whereHas('schedule', fn ($q) => $q->where('groupe_id', $filtres['groupe_id']));
        }

        if (! empty($filtres['teacher_id'])) {
            $query->where('teacher_id', $filtres['teacher_id']);
        }

        if (! empty($filtres['departement_id'])) {
            $query->whereHas('teacher', fn ($q) => $q->where('departement_id', $filtres['departement_id']));
        }

        if (! empty($filtres['type'])) {
            $query->where('type', $filtres['type']);
        }

        $slots = $query->orderBy('start_time')->orderBy('id')->get();

        if ($slots->isEmpty()) {
            return collect();
        }

        // 2. Chargement groupé (batch) des pointages bruts des enseignants concernés pour cette date
        $teacherIds = $slots->pluck('teacher_id')
            ->merge($slots->flatMap(fn ($s) => $s->exceptions->pluck('new_teacher_id')))
            ->filter()
            ->unique()
            ->values();

        $pointagesParEnseignant = TeacherPointage::whereIn('teacher_id', $teacherIds)
            ->whereDate('date', $dateNormalisee)
            ->orderBy('horodatage')
            ->get()
            ->groupBy('teacher_id');

        // 3. Rapprochement séance par séance
        $seancesRapprochees = $slots->map(function (ScheduleSlot $slot) use ($dateNormalisee, $pointagesParEnseignant, $toleranceMinutes, $fenetreMinutes) {
            /** @var ScheduleException|null $exception */
            $exception = $slot->exceptions->first();

            // Gestion des exceptions de calendrier (modification ponctuelle, déplacement ou annulation)
            $estAnnule = $slot->status === 'cancelled' || ($exception && $exception->status === 'cancelled');
            $heureDebutEffective = $exception?->new_start_time ?? $slot->start_time;
            $heureFinEffective = $exception?->new_end_time ?? $slot->end_time;
            $enseignantEffectif = $exception?->nouvelEnseignant ?? $slot->teacher;
            $salleEffective = $exception?->nouvelleSalle ?? $slot->room;

            /** @var TeacherPresence|null $presenceExistante */
            $presenceExistante = $slot->presences->first();

            $pointagesEnseignant = $enseignantEffectif
                ? ($pointagesParEnseignant->get($enseignantEffectif->id) ?? collect())
                : collect();

            return $this->rapprocherSeance(
                $slot,
                $dateNormalisee,
                $heureDebutEffective,
                $heureFinEffective,
                $enseignantEffectif,
                $salleEffective,
                $estAnnule,
                $exception,
                $pointagesEnseignant,
                $presenceExistante,
                $toleranceMinutes,
                $fenetreMinutes
            );
        });

        // 4. Filtrage a posteriori sur le statut ou les cas suspects
        if (! empty($filtres['statut'])) {
            $seancesRapprochees = $seancesRapprochees->filter(fn ($item) => $item['statut_effectif'] === $filtres['statut']);
        }

        if (! empty($filtres['a_verifier_uniquement'])) {
            $seancesRapprochees = $seancesRapprochees->filter(fn ($item) => in_array($item['statut_effectif'], [
                'retard_potentiel',
                'pointage_manquant',
                'absence_a_verifier',
            ], true));
        }

        return $seancesRapprochees->values();
    }

    /**
     * Rapproche une séance unitaire avec les pointages et statuts.
     *
     * @param  Collection<int, TeacherPointage>  $pointagesEnseignant
     * @return array<string, mixed>
     */
    public function rapprocherSeance(
        ScheduleSlot $slot,
        string $date,
        string $heureDebut,
        string $heureFin,
        ?Teacher $enseignant,
        mixed $salle,
        bool $estAnnule,
        ?ScheduleException $exception,
        Collection $pointagesEnseignant,
        ?TeacherPresence $presenceExistante,
        int $toleranceMinutes,
        int $fenetreMinutes = self::FENETRE_PAR_DEFAUT
    ): array {
        $debutSeance = Carbon::parse($date.' '.$heureDebut);
        $finSeance = Carbon::parse($date.' '.$heureFin);
        $maintenant = now();

        $pointagesAssocies = [];
        $pointageArrivee = null;
        $pointageDepart = null;

        if ($enseignant !== null && ! $estAnnule) {
            // Fenêtre de recherche administrable : de (début - fenêtre)
            // jusqu'à (fin + fenêtre). Défaut historique : 60 minutes.
            $fenetreDebut = $debutSeance->copy()->subMinutes($fenetreMinutes);
            $fenetreFin = $finSeance->copy()->addMinutes($fenetreMinutes);

            $pointagesDansFenetre = $pointagesEnseignant->filter(function (TeacherPointage $p) use ($fenetreDebut, $fenetreFin) {
                return $p->horodatage->betweenIncluded($fenetreDebut, $fenetreFin);
            });

            $pointagesAssocies = $pointagesDansFenetre->values()->all();

            // Pointage d'arrivée : le pointage de type 'entree' ou 'presence' le plus pertinent
            $pointageArrivee = $pointagesDansFenetre->first(fn (TeacherPointage $p) => in_array($p->type, ['entree', 'presence'], true))
                ?? $pointagesDansFenetre->first();

            // Pointage de départ : dernier pointage de type 'sortie' dans la fenêtre
            $pointageDepart = $pointagesDansFenetre->last(fn (TeacherPointage $p) => $p->type === 'sortie');
        }

        // Calcul des écarts horaires et du statut calculé
        $retardMinutes = 0;
        $statutCalcule = 'a_venir';
        $diagnostic = '';

        if ($estAnnule) {
            $statutCalcule = 'seance_annulee';
            $motifAnnulation = $exception?->reason ?? $slot->notes ?? 'Séance annulée dans l\'emploi du temps';
            $diagnostic = 'Séance annulée : '.$motifAnnulation;
        } elseif ($enseignant === null) {
            $statutCalcule = 'pointage_manquant';
            $diagnostic = 'Aucun enseignant assigné sur ce créneau d\'emploi du temps.';
        } elseif ($pointageArrivee !== null) {
            $horodatageArrivee = $pointageArrivee->horodatage;
            if ($horodatageArrivee->gt($debutSeance)) {
                $retardMinutes = (int) max(0, round($horodatageArrivee->diffInMinutes($debutSeance, false) * -1));
            }

            if ($retardMinutes > $toleranceMinutes) {
                $statutCalcule = 'retard_potentiel';
                $diagnostic = sprintf(
                    'Pointage d\'arrivée à %s avec un retard calculé de %d min (dépassement tolérance de %d min).',
                    $horodatageArrivee->format('H:i'),
                    $retardMinutes,
                    $toleranceMinutes
                );
            } else {
                $statutCalcule = 'presence_confirmee';
                $diagnostic = sprintf(
                    'Présence constatée à %s%s.',
                    $horodatageArrivee->format('H:i'),
                    $retardMinutes > 0 ? " (retard toléré de {$retardMinutes} min)" : ' (à l\'heure / en avance)'
                );
            }

            if ($pointageDepart !== null && $pointageDepart->horodatage->lt($horodatageArrivee)) {
                $diagnostic .= ' Incohérence : le pointage de sortie précède le pointage d\'arrivée.';
            }
        } else {
            // Aucun pointage détecté
            if ($maintenant->lt($debutSeance->copy()->subMinutes($toleranceMinutes))) {
                $statutCalcule = 'a_venir';
                $diagnostic = 'Séance planifiée future.';
            } elseif ($maintenant->lte($finSeance)) {
                $statutCalcule = 'en_cours';
                $diagnostic = 'Séance actuellement en cours (aucun pointage détecté à cet instant).';
            } else {
                // Séance passée sans aucun pointage : alerte "absence à vérifier", JAMAIS d'absence validée arbitraire
                $statutCalcule = 'absence_a_verifier';
                $diagnostic = 'Séance terminée sans aucun pointage enregistré. Absence potentielle à vérifier par la scolarité.';
            }
        }

        // Prise en compte de la décision humaine persistée (prioritaire)
        $statutValide = $presenceExistante?->statut_valide;
        $statutEffectif = $statutValide ?? $statutCalcule;

        return [
            'slot_id' => $slot->id,
            'slot' => $slot,
            'presence_id' => $presenceExistante?->id,
            'presence' => $presenceExistante,
            'date' => $date,
            'heure_debut' => $heureDebut,
            'heure_fin' => $heureFin,
            'duree_minutes' => $debutSeance->diffInMinutes($finSeance),
            'enseignant' => $enseignant,
            'matiere' => $slot->matiere,
            'groupe' => $slot->schedule?->groupe,
            'salle' => $salle,
            'type_seance' => $slot->type,
            'heure_arrivee' => $presenceExistante?->heure_arrivee ?? ($pointageArrivee ? $pointageArrivee->horodatage->format('H:i:s') : null),
            'heure_depart' => $presenceExistante?->heure_depart ?? ($pointageDepart ? $pointageDepart->horodatage->format('H:i:s') : null),
            'retard_minutes' => $presenceExistante?->retard_minutes ?? $retardMinutes,
            'statut_calcule' => $statutCalcule,
            'statut_valide' => $statutValide,
            'statut_effectif' => $statutEffectif,
            'motif' => $presenceExistante?->motif,
            'valide_par' => $presenceExistante?->validePar,
            'valide_le' => $presenceExistante?->valide_le,
            'diagnostic' => $diagnostic,
            'pointages' => $pointagesAssocies,
            'pointage_arrivee' => $pointageArrivee,
            'pointage_depart' => $pointageDepart,
            'historique' => $presenceExistante?->historique ?? [],
            'a_verifier' => in_array($statutEffectif, ['retard_potentiel', 'pointage_manquant', 'absence_a_verifier'], true),
        ];
    }

    /**
     * Calcule et enregistre de manière idempotente les observations de présence
     * pour toutes les séances du jour. Préserve strictement toute validation humaine antérieure.
     *
     * @return array<string, int> Indicateurs KPIs synthétiques
     */
    public function reconcilierEtPersister(
        string $date,
        ?int $faculteId = null,
        ?int $auteurId = null,
        int $toleranceMinutes = self::TOLERANCE_PAR_DEFAUT,
        int $fenetreMinutes = self::FENETRE_PAR_DEFAUT
    ): array {
        $seances = $this->seancesDuJour($date, $faculteId, [], $toleranceMinutes, $fenetreMinutes);

        $kpis = [
            'total_seances' => 0,
            'presences_confirmees' => 0,
            'retards_potentiels' => 0,
            'pointages_manquants' => 0,
            'absences_a_verifier' => 0,
            'absences_validees' => 0,
            'seances_annulees' => 0,
            // Compteurs complémentaires : la somme des compteurs doit
            // couvrir exactement le total (aucune séance comptée deux
            // fois, aucune ignorée silencieusement).
            'dispenses_missions' => 0,
            'retards_justifies' => 0,
            'absences_rejetees' => 0,
            'seances_a_venir' => 0,
            'seances_en_cours' => 0,
        ];

        DB::transaction(function () use ($seances, $auteurId, &$kpis) {
            foreach ($seances as $seance) {
                $kpis['total_seances']++;

                /** @var TeacherPresence $presence */
                $presence = TeacherPresence::where('schedule_slot_id', $seance['slot_id'])
                    ->whereDate('date', $seance['date'])
                    ->lockForUpdate()
                    ->first();

                if ($presence === null) {
                    $presence = new TeacherPresence([
                        'schedule_slot_id' => $seance['slot_id'],
                        'teacher_id' => $seance['enseignant']?->id ?? $seance['slot']->teacher_id,
                        'date' => $seance['date'],
                    ]);
                }

                // Mise à jour de l'état calculé et des horaires détectés
                $ancienStatutCalcule = $presence->statut_calcule;
                $presence->statut_calcule = $seance['statut_calcule'];
                $presence->retard_minutes = $seance['retard_minutes'];

                if ($presence->heure_arrivee === null && $seance['heure_arrivee'] !== null) {
                    $presence->heure_arrivee = $seance['heure_arrivee'];
                }

                if ($presence->heure_depart === null && $seance['heure_depart'] !== null) {
                    $presence->heure_depart = $seance['heure_depart'];
                }

                if ($presence->isDirty('statut_calcule')) {
                    $presence->ajouterTrace(
                        'reconciliation_automatique',
                        sprintf('Recalcul : %s -> %s', $ancienStatutCalcule ?? 'initial', $presence->statut_calcule),
                        $auteurId,
                        ['retard_minutes' => $presence->retard_minutes]
                    );
                }

                $presence->save();

                // Incrémentation des compteurs KPIs (partition exhaustive)
                match ($presence->statutEffectif()) {
                    'presence_confirmee' => $kpis['presences_confirmees']++,
                    'retard_potentiel' => $kpis['retards_potentiels']++,
                    'pointage_manquant' => $kpis['pointages_manquants']++,
                    'absence_a_verifier' => $kpis['absences_a_verifier']++,
                    'absence_validee' => $kpis['absences_validees']++,
                    'seance_annulee' => $kpis['seances_annulees']++,
                    'dispense_mission' => $kpis['dispenses_missions']++,
                    'retard_justifie' => $kpis['retards_justifies']++,
                    'absence_rejetee' => $kpis['absences_rejetees']++,
                    'a_venir' => $kpis['seances_a_venir']++,
                    'en_cours' => $kpis['seances_en_cours']++,
                    default => null,
                };
            }
        });

        IntegrationLog::tracer(
            'presences_enseignants',
            'in',
            'reconciliation',
            'completed',
            null,
            200,
            null,
            null,
            null,
            "Date {$date} - {$kpis['total_seances']} séances traitées"
        );

        return $kpis;
    }

    /**
     * Enregistre un pointage brut pour un enseignant.
     *
     * @param  int|null  $faculteIdScope  Périmètre de l'agent agissant : la
     *                                    réconciliation immédiate ne doit JAMAIS écrire des observations hors
     *                                    de sa faculté (null = agent global, toutes facultés).
     */
    public function enregistrerPointage(
        Teacher|int $teacher,
        string $horodatage,
        string $type = 'entree',
        string $source = 'manuel',
        ?int $auteurId = null,
        ?string $notes = null,
        ?string $terminalIp = null,
        ?int $faculteIdScope = null
    ): TeacherPointage {
        $teacherId = $teacher instanceof Teacher ? $teacher->id : $teacher;
        $dt = Carbon::parse($horodatage);

        return DB::transaction(function () use ($teacherId, $dt, $type, $source, $auteurId, $notes, $terminalIp, $faculteIdScope) {
            // Anti-doublon (§6) : un pointage identique (même enseignant,
            // même minute, même type) est une soumission répétée, jamais un
            // second événement. Le brut existant est retourné tel quel.
            $existant = TeacherPointage::where('teacher_id', $teacherId)
                ->where('type', $type)
                ->whereBetween('horodatage', [
                    $dt->copy()->startOfMinute()->toDateTimeString(),
                    $dt->copy()->startOfMinute()->addMinute()->subSecond()->toDateTimeString(),
                ])
                ->first();

            if ($existant !== null) {
                return $existant;
            }

            $pointage = TeacherPointage::create([
                'teacher_id' => $teacherId,
                'date' => $dt->format('Y-m-d'),
                'horodatage' => $dt->toDateTimeString(),
                'type' => $type,
                'source' => $source,
                'enregistre_par' => $auteurId,
                'notes' => $notes,
                'terminal_ip' => $terminalIp,
            ]);

            // Réconciliation immédiate des séances de la journée, STRICTEMENT
            // dans le périmètre de l'agent (isolation faculté).
            $this->reconcilierEtPersister($dt->format('Y-m-d'), $faculteIdScope, $auteurId);

            return $pointage;
        });
    }

    /**
     * Valide officiellement une absence avec motif obligatoire.
     */
    public function validerAbsence(
        TeacherPresence|int $presence,
        string $motif,
        int $auteurId
    ): TeacherPresence {
        if (trim($motif) === '') {
            throw new InvalidArgumentException('Un motif explicite est obligatoire pour valider une absence.');
        }

        $record = $presence instanceof TeacherPresence ? $presence : TeacherPresence::findOrFail($presence);

        return DB::transaction(function () use ($record, $motif, $auteurId) {
            $ancienStatut = $record->statutEffectif();

            $record->statut_valide = 'absence_validee';
            $record->motif = $motif;
            $record->valide_par = $auteurId;
            $record->valide_le = now();

            $record->ajouterTrace(
                'validation_absence',
                $motif,
                $auteurId,
                ['ancien_statut' => $ancienStatut, 'nouveau_statut' => 'absence_validee']
            );

            $record->save();

            return $record;
        });
    }

    /**
     * Confirme manuellement une présence (ex: émargement papier, régularisation administrative).
     */
    public function confirmerPresence(
        TeacherPresence|int $presence,
        ?string $heureArrivee = null,
        ?string $heureDepart = null,
        ?string $motif = null,
        ?int $auteurId = null
    ): TeacherPresence {
        $record = $presence instanceof TeacherPresence ? $presence : TeacherPresence::findOrFail($presence);

        return DB::transaction(function () use ($record, $heureArrivee, $heureDepart, $motif, $auteurId) {
            $ancienStatut = $record->statutEffectif();

            $record->statut_valide = 'presence_confirmee';
            if ($heureArrivee !== null) {
                $record->heure_arrivee = $heureArrivee;
            }
            if ($heureDepart !== null) {
                $record->heure_depart = $heureDepart;
            }
            if ($motif !== null) {
                $record->motif = $motif;
            }
            $record->valide_par = $auteurId;
            $record->valide_le = now();

            $record->ajouterTrace(
                'confirmation_presence',
                $motif ?? 'Confirmation manuelle de présence',
                $auteurId,
                ['ancien_statut' => $ancienStatut, 'nouveau_statut' => 'presence_confirmee']
            );

            $record->save();

            return $record;
        });
    }

    /**
     * Corrige un pointage ou un statut avec justification obligatoire.
     *
     * @param  array<string, mixed>  $donnees
     */
    public function corrigerPresence(
        TeacherPresence|int $presence,
        array $donnees,
        string $motif,
        int $auteurId
    ): TeacherPresence {
        if (trim($motif) === '') {
            throw new InvalidArgumentException('Un motif est obligatoire pour toute correction de présence.');
        }

        $record = $presence instanceof TeacherPresence ? $presence : TeacherPresence::findOrFail($presence);

        return DB::transaction(function () use ($record, $donnees, $motif, $auteurId) {
            $anciennesValeurs = [
                'statut_valide' => $record->statut_valide,
                'heure_arrivee' => $record->heure_arrivee,
                'heure_depart' => $record->heure_depart,
                'motif' => $record->motif,
            ];

            if (isset($donnees['statut_valide'])) {
                $record->statut_valide = $donnees['statut_valide'];
            }
            if (array_key_exists('heure_arrivee', $donnees)) {
                $record->heure_arrivee = $donnees['heure_arrivee'];
            }
            if (array_key_exists('heure_depart', $donnees)) {
                $record->heure_depart = $donnees['heure_depart'];
            }

            $record->motif = $motif;
            $record->valide_par = $auteurId;
            $record->valide_le = now();

            $record->ajouterTrace(
                'correction_manuelle',
                $motif,
                $auteurId,
                [
                    'anciennes' => $anciennesValeurs,
                    'nouvelles' => $donnees,
                ]
            );

            $record->save();

            return $record;
        });
    }

    /**
     * Enregistre une dispense administrative ou une mission officielle pour une séance.
     */
    public function dispenserOuMission(
        TeacherPresence|int $presence,
        string $motif,
        int $auteurId
    ): TeacherPresence {
        if (trim($motif) === '') {
            throw new InvalidArgumentException('Un justificatif de dispense ou d\'ordre de mission est requis.');
        }

        $record = $presence instanceof TeacherPresence ? $presence : TeacherPresence::findOrFail($presence);

        return DB::transaction(function () use ($record, $motif, $auteurId) {
            $ancienStatut = $record->statutEffectif();

            $record->statut_valide = 'dispense_mission';
            $record->motif = $motif;
            $record->valide_par = $auteurId;
            $record->valide_le = now();

            $record->ajouterTrace(
                'dispense_mission',
                $motif,
                $auteurId,
                ['ancien_statut' => $ancienStatut, 'nouveau_statut' => 'dispense_mission']
            );

            $record->save();

            return $record;
        });
    }

    /**
     * Statuts effectifs constituant une anomalie à vérifier (jamais une
     * absence confirmée : une anomalie non vérifiée n'est ni présence ni
     * absence).
     *
     * @return array<int, string>
     */
    public static function statutsAnomalie(): array
    {
        return ['absence_a_verifier', 'pointage_manquant', 'retard_potentiel'];
    }

    /**
     * Statistiques d'assiduité d'un enseignant sur une période (pur).
     * Taux = (présences confirmées + dispenses) / séances échues non
     * annulées. Les anomalies non vérifiées restent au dénominateur
     * (elles pèsent, sans être qualifiées d'absences) ; annulées et non
     * échues (`a_venir`, `en_cours`) sont exclues et comptées à part.
     * Bornes nulles = tout l'historique.
     *
     * @return array{total: int, prises_en_compte: int, confirmees: int, dispenses: int, anomalies: int, absences_a_verifier: int, absences_validees: int, pointages_manquants: int, retards: int, annulees: int, non_echues: int, retard_minutes: int, taux: ?float}
     */
    public function statistiquesEnseignant(int $teacherId, ?string $debut, ?string $fin): array
    {
        // whereDate (et non whereBetween) : la colonne `date` est
        // stockée en datetime selon le driver (SQLite), les bornes jour
        // doivent matcher la partie date quelle que soit l'heure stockée.
        $lignes = TeacherPresence::where('teacher_id', $teacherId)
            ->when($debut !== null, fn ($q) => $q->whereDate('date', '>=', $debut))
            ->when($fin !== null, fn ($q) => $q->whereDate('date', '<=', $fin))
            ->get(['statut_calcule', 'statut_valide', 'retard_minutes']);

        $stats = [
            'total' => $lignes->count(), 'prises_en_compte' => 0, 'confirmees' => 0,
            'dispenses' => 0, 'retards' => 0, 'pointages_manquants' => 0,
            'absences_a_verifier' => 0, 'absences_validees' => 0,
            'anomalies' => 0, 'annulees' => 0, 'non_echues' => 0,
            'retard_minutes' => 0, 'taux' => null,
        ];

        foreach ($lignes as $ligne) {
            $effectif = $ligne->statut_valide ?? $ligne->statut_calcule;

            if ($effectif === 'seance_annulee') {
                $stats['annulees']++;

                continue;
            }

            if (in_array($effectif, ['a_venir', 'en_cours'], true)) {
                $stats['non_echues']++;

                continue;
            }

            $stats['prises_en_compte']++;

            if ($effectif === 'presence_confirmee') {
                $stats['confirmees']++;
            } elseif ($effectif === 'dispense_mission') {
                $stats['dispenses']++;
            } elseif ($effectif === 'absence_validee') {
                $stats['absences_validees']++;
            } elseif (in_array($effectif, ['retard_potentiel', 'retard_justifie'], true)) {
                $stats['retards']++;
                if ($effectif === 'retard_potentiel') {
                    $stats['anomalies']++;
                }
            } elseif ($effectif === 'pointage_manquant') {
                $stats['pointages_manquants']++;
                $stats['anomalies']++;
            } elseif ($effectif === 'absence_a_verifier') {
                $stats['absences_a_verifier']++;
                $stats['anomalies']++;
            }

            $stats['retard_minutes'] += (int) $ligne->retard_minutes;
        }

        if ($stats['prises_en_compte'] > 0) {
            $stats['taux'] = round(100 * ($stats['confirmees'] + $stats['dispenses']) / $stats['prises_en_compte'], 1);
        }

        return $stats;
    }

    /**
     * Pointages orphelins : aucun créneau de l'enseignant ne couvre
     * l'horodatage (fenêtre d'appariement incluse). Borné (50 derniers).
     *
     * @return Collection<int, TeacherPointage>
     */
    public function pointagesOrphelins(int $teacherId, string $debut, string $fin, ?int $fenetreMinutes = null): Collection
    {
        $fenetre = $fenetreMinutes ?? self::FENETRE_PAR_DEFAUT;

        $slots = ScheduleSlot::where('teacher_id', $teacherId)
            ->where('status', '!=', 'cancelled')
            ->get(['id', 'day_of_week', 'start_time', 'end_time']);

        $orphelins = collect();

        TeacherPointage::where('teacher_id', $teacherId)
            ->whereDate('date', '>=', $debut)
            ->whereDate('date', '<=', $fin)
            ->orderByDesc('horodatage')
            ->chunk(200, function ($lot) use ($slots, $fenetre, $orphelins) {
                foreach ($lot as $pointage) {
                    $jour = strtolower($pointage->horodatage->locale('en')->isoFormat('dddd'));
                    $couvert = $slots
                        ->where('day_of_week', $jour)
                        ->contains(fn (ScheduleSlot $slot) => $this->dansFenetre($pointage, $slot, $fenetre));

                    if (! $couvert) {
                        $orphelins->push($pointage);
                    }

                    if ($orphelins->count() >= 50) {
                        return false;
                    }
                }

                return true;
            });

        return $orphelins->take(50)->values();
    }

    private function dansFenetre(TeacherPointage $pointage, ScheduleSlot $slot, int $fenetre): bool
    {
        // `horodatage` fait foi (le champ `date` peut porter une heure
        // selon le driver) : séance du même jour calendaire couverte.
        $jourPointage = $pointage->horodatage->toDateString();
        $debut = Carbon::parse($jourPointage.' '.$slot->start_time)->subMinutes($fenetre);
        $fin = Carbon::parse($jourPointage.' '.$slot->end_time)->addMinutes($fenetre);

        return $pointage->horodatage->betweenIncluded($debut, $fin);
    }
}
