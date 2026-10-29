<?php

namespace App\Modules\Schedule\Services;

use App\Models\IntegrationLog;
use App\Models\Matiere;
use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Evaluation\Services\CorrespondanceExamenService;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleAvailability;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Extension pour la planification et l'ordonnancement des examens universitaires (Phase 12 / LOT 06).
 * Gestion des ratios de capacité avec espacement, règles anti-conflits étudiants/surveillants,
 * moteur de contraintes dures et préférences souples, production de scénarios comparables
 * et conformité réglementaire des sessions d'évaluations.
 */
class ExamScheduleEngineService
{
    /** Ratio d'espacement standard en examen : 1 place sur 2 (50% de capacité nominale). */
    public const RATIO_CAPACITE_EXAMEN_DEFAUT = 0.50;

    /**
     * Calcule la capacité d'accueil utile d'une salle en configuration d'examen (avec espacement anti-fraude).
     */
    public function capaciteUtileExamen(Room $salle, float $ratio = self::RATIO_CAPACITE_EXAMEN_DEFAUT): int
    {
        return (int) floor(($salle->capacite ?? 0) * $ratio);
    }

    /**
     * Planifie une épreuve d'examen sous transaction avec contrôles stricts de capacité et anti-conflits.
     *
     * @param array{
     *     code?: string,
     *     examen_id?: int,
     *     session_id?: int,
     *     annee_universitaire_id?: int,
     *     matiere_id: int,
     *     groupe_id?: int,
     *     salle_id?: int,
     *     date: string,
     *     heure_debut: string,
     *     heure_fin: string,
     *     duree_minutes?: int,
     *     ratio_espacement?: float,
     *     coefficient?: float,
     *     bareme?: float,
     *     note_max?: float,
     *     capacite_necessaire?: int
     * } $data
     *
     * @throws DomainException
     */
    public function planifierEpreuve(array $data, ?int $userId = null): Epreuve
    {
        $dateStr = Carbon::parse($data['date'])->format('Y-m-d');
        $hDeb = strlen($data['heure_debut']) === 5 ? $data['heure_debut'].':00' : $data['heure_debut'];
        $hFin = strlen($data['heure_fin']) === 5 ? $data['heure_fin'].':00' : $data['heure_fin'];

        if ($hDeb >= $hFin) {
            throw new DomainException("L'heure de début ({$hDeb}) doit être strictement antérieure à l'heure de fin ({$hFin}).");
        }

        // Effectif résolu une seule fois, avant tout branchement : la variable
        // est aussi lue dans la transaction d'écriture. Sans salle, la
        // capacité du groupe reste l'effectif de référence (jamais un 25
        // arbitraire qui sous-estimerait un groupe de 100).
        $effectif = (int) ($data['capacite_necessaire'] ?? 0);

        if ($effectif === 0 && ! empty($data['groupe_id'])) {
            $effectif = (int) (Group::find($data['groupe_id'])?->capacite ?? 0);
        }

        // 1. Vérification de la salle et du ratio d'espacement
        if (! empty($data['salle_id'])) {
            /** @var Room|null $salle */
            $salle = Room::find($data['salle_id']);
            if (! $salle) {
                throw new DomainException("La salle sélectionnée (ID {$data['salle_id']}) n'existe pas.");
            }
            if (! $salle->actif) {
                throw new DomainException("La salle '{$salle->nom}' est fermée ou désactivée.");
            }

            // Conflit d'occupation de la salle d'examen
            $salleOccupee = Epreuve::where('salle_id', $salle->id)
                ->whereDate('date', $dateStr)
                ->where('statut', '!=', 'cancelled')
                ->where(function ($q) use ($hDeb, $hFin) {
                    $q->where('heure_debut', '<', $hFin)
                        ->where('heure_fin', '>', $hDeb);
                })
                ->first();

            if ($salleOccupee) {
                throw new DomainException("La salle '{$salle->nom}' est déjà réservée pour une autre épreuve (#{$salleOccupee->code}) le {$dateStr} de {$salleOccupee->heure_debut} à {$salleOccupee->heure_fin}.");
            }

            // Capacité d'examen avec espacement
            $ratio = $data['ratio_espacement'] ?? self::RATIO_CAPACITE_EXAMEN_DEFAUT;
            $capaUtile = $this->capaciteUtileExamen($salle, $ratio);

            if ($effectif > 0 && $capaUtile < $effectif) {
                throw new DomainException("Capacité d'examen insuffisante pour la salle '{$salle->nom}' : {$capaUtile} places utiles avec espacement (effectif requis: {$effectif} candidats).");
            }
        }

        // 2. Vérification des conflits pour le groupe d'étudiants
        if (! empty($data['groupe_id'])) {
            $groupeConflit = Epreuve::where('groupe_id', $data['groupe_id'])
                ->whereDate('date', $dateStr)
                ->where('statut', '!=', 'cancelled')
                ->where(function ($q) use ($hDeb, $hFin) {
                    $q->where('heure_debut', '<', $hFin)
                        ->where('heure_fin', '>', $hDeb);
                })
                ->first();

            if ($groupeConflit) {
                throw new DomainException("Le groupe d'étudiants a déjà une épreuve programmée sur ce créneau (#{$groupeConflit->code} de {$groupeConflit->heure_debut} à {$groupeConflit->heure_fin}).");
            }
        }

        $sessionId = $data['session_id'] ?? null;
        $anneeId = $data['annee_universitaire_id'] ?? null;

        if (! empty($data['examen_id']) && (! $sessionId || ! $anneeId)) {
            $ex = Examen::find($data['examen_id']);
            if ($ex) {
                $sessionId ??= $ex->session_id;
                $anneeId ??= $ex->annee_universitaire_id;
            }
        }

        // 3. Vérification de la cohérence structurelle et pédagogique matière ↔ groupe ↔ examen
        $epreuveTemp = new Epreuve([
            'examen_id' => $data['examen_id'] ?? null,
            'session_id' => $sessionId,
            'annee_universitaire_id' => $anneeId,
            'matiere_id' => $data['matiere_id'],
            'groupe_id' => $data['groupe_id'] ?? null,
            'salle_id' => $data['salle_id'] ?? null,
        ]);
        $coherenceGroupe = app(CorrespondanceExamenService::class)
            ->verifierCoherenceStructurelleGroupe($epreuveTemp);

        if (! $coherenceGroupe['conforme']) {
            throw new DomainException($coherenceGroupe['anomalies'][0]);
        }

        return DB::transaction(function () use ($data, $dateStr, $hDeb, $hFin, $sessionId, $anneeId, $effectif) {
            $code = $data['code'] ?? 'EPR_'.strtoupper(substr(uniqid(), -8));
            $debutC = Carbon::createFromFormat('H:i:s', $hDeb);
            $finC = Carbon::createFromFormat('H:i:s', $hFin);
            $duree = $data['duree_minutes'] ?? (int) abs($debutC->diffInMinutes($finC));

            $capaReq = $data['capacite_necessaire'] ?? ($effectif > 0 ? $effectif : 25);

            return Epreuve::create([
                'code' => $code,
                'examen_id' => $data['examen_id'] ?? null,
                'session_id' => $sessionId,
                'annee_universitaire_id' => $anneeId,
                'matiere_id' => $data['matiere_id'],
                'groupe_id' => $data['groupe_id'] ?? null,
                'salle_id' => $data['salle_id'] ?? null,
                'date' => $dateStr,
                'heure_debut' => $hDeb,
                'heure_fin' => $hFin,
                'duree_minutes' => $duree,
                'coefficient' => $data['coefficient'] ?? 1.0,
                'bareme' => $data['bareme'] ?? 20.0,
                'note_max' => $data['note_max'] ?? 20.0,
                'capacite_necessaire' => $capaReq,
                'statut' => 'scheduled',
            ]);
        });
    }

    /**
     * Affecte une équipe de surveillants à une épreuve en validant les quotas et disponibilités.
     *
     * @param  array<int, int>  $teacherIds
     * @return array<int, Surveillance>
     *
     * @throws DomainException
     */
    public function affecterSurveillants(Epreuve $epreuve, array $teacherIds, ?int $userId = null): array
    {
        if (empty($teacherIds)) {
            throw new DomainException('Au moins un enseignant surveillant doit être affecté.');
        }

        $salle = $epreuve->salle;
        $minRequis = $salle?->min_surveillants ?? 1;
        if (count($teacherIds) < $minRequis) {
            throw new DomainException("Nombre insuffisant de surveillants pour la salle '{$salle?->nom}' : au moins {$minRequis} requis.");
        }

        $dateStr = Carbon::parse($epreuve->date)->format('Y-m-d');
        $debut = $epreuve->heure_debut;
        $fin = $epreuve->heure_fin;

        // Contrôle des disponibilités de chaque surveillant
        foreach ($teacherIds as $tId) {
            /** @var Teacher|null $ens */
            $ens = Teacher::find($tId);
            if (! $ens || ! $ens->actif) {
                throw new DomainException("L'enseignant (ID {$tId}) n'existe pas ou est inactif.");
            }

            // Déjà en surveillance ailleurs sur la même plage
            $dejaEnSurveillance = Surveillance::where('teacher_id', $tId)
                ->where('epreuve_id', '!=', $epreuve->id)
                ->where('statut', '!=', 'annule')
                ->whereHas('epreuve', function ($q) use ($dateStr, $debut, $fin) {
                    $q->whereDate('date', $dateStr)
                        ->where('heure_debut', '<', $fin)
                        ->where('heure_fin', '>', $debut);
                })
                ->first();

            if ($dejaEnSurveillance) {
                throw new DomainException("L'enseignant {$ens->nomComplet()} est déjà affecté à la surveillance de l'épreuve #{$dejaEnSurveillance->epreuve?->code} sur ce même créneau.");
            }
        }

        return DB::transaction(function () use ($epreuve, $teacherIds, $salle) {
            $surveillances = [];
            foreach ($teacherIds as $idx => $tId) {
                $role = ($idx === 0) ? 'principal' : 'surveillant';
                $surveillances[] = Surveillance::create([
                    'epreuve_id' => $epreuve->id,
                    'teacher_id' => $tId,
                    'salle_id' => $salle?->id,
                    'role' => $role,
                    'statut' => 'assigne',
                ]);
            }

            return $surveillances;
        });
    }

    /**
     * Recherche des créneaux d'examens optimaux pour un groupe et une matière
     * en respectant les fenêtres de révision et les salles adaptées.
     *
     * @return array<int, array{
     *     date: string,
     *     heure_debut: string,
     *     heure_fin: string,
     *     salle_recommandee: Room,
     *     capacite_examen: int,
     *     score_confort: int
     * }>
     */
    public function suggererCreneauxExamens(
        Group $groupe,
        Matiere $matiere,
        string $dateDebut,
        string $dateFin,
        int $dureeMinutes = 120
    ): array {
        $debut = Carbon::parse($dateDebut);
        $fin = Carbon::parse($dateFin);
        $effectif = $groupe->capacite ?? 25;

        // Salles éligibles avec capacité d'examen suffisante
        $salles = Room::where('actif', true)
            ->where('faculte_id', $groupe->faculte_id)
            ->get()
            ->filter(fn (Room $s) => $this->capaciteUtileExamen($s) >= $effectif)
            ->values();

        if ($salles->isEmpty()) {
            // Repli sur toutes les salles sans filtre faculté stricte si nécessaire
            $salles = Room::where('actif', true)
                ->get()
                ->filter(fn (Room $s) => $this->capaciteUtileExamen($s) >= $effectif)
                ->values();
        }

        $suggestions = [];
        $curDate = $debut->copy();

        // Plages horaires types pour les examens universitaires
        $plages = [
            ['debut' => '08:30:00', 'fin' => '10:30:00', 'confort' => 95],
            ['debut' => '11:00:00', 'fin' => '13:00:00', 'confort' => 85],
            ['debut' => '14:30:00', 'fin' => '16:30:00', 'confort' => 80],
        ];

        while ($curDate->lte($fin) && count($suggestions) < 5) {
            // Ignorer le dimanche
            if ($curDate->isSunday()) {
                $curDate->addDay();

                continue;
            }

            $dateStr = $curDate->format('Y-m-d');

            // Vérifier si le groupe a déjà un examen ce jour-là (privilégier 1 examen / jour)
            $examensCeJour = Epreuve::where('groupe_id', $groupe->id)
                ->whereDate('date', $dateStr)
                ->where('statut', '!=', 'cancelled')
                ->count();

            if ($examensCeJour > 0) {
                $curDate->addDay();

                continue;
            }

            foreach ($plages as $plage) {
                // Trouver une salle libre sur cette plage
                foreach ($salles as $salle) {
                    $occupee = Epreuve::where('salle_id', $salle->id)
                        ->whereDate('date', $dateStr)
                        ->where('statut', '!=', 'cancelled')
                        ->where(function ($q) use ($plage) {
                            $q->where('heure_debut', '<', $plage['fin'])
                                ->where('heure_fin', '>', $plage['debut']);
                        })
                        ->exists();

                    if (! $occupee) {
                        $suggestions[] = [
                            'date' => $dateStr,
                            'heure_debut' => $plage['debut'],
                            'heure_fin' => $plage['fin'],
                            'salle_recommandee' => $salle,
                            'capacite_examen' => $this->capaciteUtileExamen($salle),
                            'score_confort' => $plage['confort'],
                        ];
                        break; // Une salle suffit par plage
                    }
                }

                if (count($suggestions) >= 5) {
                    break;
                }
            }

            $curDate->addDay();
        }

        return $suggestions;
    }

    /**
     * Audite la conformité globale d'une session d'examens (salles, surveillants, conflits).
     *
     * @return array{
     *     conforme: bool,
     *     score_conformite: int,
     *     nb_epreuves: int,
     *     epreuves_sans_salle: int,
     *     epreuves_sous_surveillees: int,
     *     conflits_bloquants: array<int, string>
     * }
     */
    public const SCENARIO_ETALE = 'SCENARIO_ETALE';

    public const SCENARIO_COMPACT = 'SCENARIO_COMPACT';

    public const SCENARIO_AMPHI = 'SCENARIO_AMPHI';

    public const SCENARIOS = [
        self::SCENARIO_ETALE => 'Scénario A — Confort & Révision (Étalé)',
        self::SCENARIO_COMPACT => 'Scénario B — Compact & Optimisation Salles',
        self::SCENARIO_AMPHI => 'Scénario C — Grands Espaces & Amphis Prioritaires',
    ];

    /**
     * Audite la conformité globale d'une session ou d'un examen (salles, surveillants, capacités, collisions).
     *
     * @return array{
     *     conforme: bool,
     *     score_conformite: int,
     *     nb_epreuves: int,
     *     epreuves_sans_salle: int,
     *     epreuves_sous_surveillees: int,
     *     conflits_bloquants: array<int, string>
     * }
     */
    public function auditerSessionExamens(Examen|int $examenOuId): array
    {
        $examenId = is_numeric($examenOuId) ? (int) $examenOuId : $examenOuId->id;
        $epreuves = Epreuve::where('examen_id', $examenId)
            ->where('statut', '!=', 'cancelled')
            ->with(['salle', 'surveillants', 'matiere', 'groupe', 'candidats.salle'])
            ->get();

        $sansSalle = 0;
        $sousSurveillees = 0;
        $conflitsBloquants = [];

        if ($epreuves->isEmpty()) {
            return [
                'conforme' => false,
                'score_conformite' => 0,
                'nb_epreuves' => 0,
                'epreuves_sans_salle' => 0,
                'epreuves_sous_surveillees' => 0,
                'conflits_bloquants' => ['Aucune épreuve planifiée pour cet examen.'],
            ];
        }

        foreach ($epreuves as $epreuve) {
            $sallesMobilisees = collect();
            if ($epreuve->salle) {
                $sallesMobilisees->put((int) $epreuve->salle->id, $epreuve->salle);
            }
            foreach ($epreuve->candidats as $cand) {
                if ($cand->salle) {
                    $sallesMobilisees->put((int) $cand->salle->id, $cand->salle);
                }
            }

            // 1. Absence de salle
            if ($sallesMobilisees->isEmpty()) {
                $sansSalle++;
                $conflitsBloquants[] = "Épreuve #{$epreuve->code} ({$epreuve->matiere?->nom}) sans salle assignée.";

                continue;
            }

            $sallePrincipale = $epreuve->salle ?? $sallesMobilisees->first();

            // 2. Salle(s) inactive(s)
            foreach ($sallesMobilisees as $salleMob) {
                if (! $salleMob->actif) {
                    $conflitsBloquants[] = "Épreuve #{$epreuve->code} : salle '{$salleMob->nom}' inactive.";
                }
            }

            // 3. Vérification des surveillants
            $minSurv = $sallePrincipale->min_surveillants ?? 1;
            $nbSurv = $epreuve->surveillants->where('statut', '!=', 'annule')->count();
            if ($nbSurv < $minSurv) {
                $sousSurveillees++;
                $conflitsBloquants[] = "Épreuve #{$epreuve->code} : nombre de surveillants insuffisant ({$nbSurv}/{$minSurv}).";
            }

            // 4. Capacité d'examen avec espacement D-04 (mono-salle ou combinaison multi-salles)
            if ($epreuve->groupe || $epreuve->candidats->isNotEmpty()) {
                $capaUtileTotale = $sallesMobilisees->sum(fn (Room $s) => $this->capaciteUtileExamen($s));
                $eff = max((int) ($epreuve->groupe?->capacite ?? 0), $epreuve->candidats->count());

                if ($sallesMobilisees->count() === 1) {
                    if ($eff > $capaUtileTotale) {
                        $conflitsBloquants[] = "Épreuve #{$epreuve->code} : salle '{$sallePrincipale->nom}' sous-capacitaire avec espacement ({$capaUtileTotale} places pour {$eff} candidats).";
                    }
                } else {
                    foreach ($sallesMobilisees as $salleMob) {
                        $capaSalle = $this->capaciteUtileExamen($salleMob);
                        $nbCandSalle = $epreuve->candidats
                            ->filter(fn ($c) => (int) ($c->salle_id ?? $epreuve->salle_id) === (int) $salleMob->id)
                            ->count();
                        if ($nbCandSalle > $capaSalle) {
                            $conflitsBloquants[] = "Épreuve #{$epreuve->code} : salle '{$salleMob->nom}' sous-capacitaire avec espacement ({$capaSalle} places pour {$nbCandSalle} candidats).";
                        }
                    }
                    if ($eff > $capaUtileTotale) {
                        $conflitsBloquants[] = "Épreuve #{$epreuve->code} : combinaison de salles sous-capacitaire avec espacement ({$capaUtileTotale} places pour {$eff} candidats).";
                    }
                }
            }

            // 5. Créneau : ouverture, indisponibilités et cours réguliers sur toutes les salles mobilisées
            if ($epreuve->date && $epreuve->heure_debut && $epreuve->heure_fin) {
                $dateStr = Carbon::parse($epreuve->date)->format('Y-m-d');
                $jourSemaine = strtolower(Carbon::parse($epreuve->date)->format('l'));
                $hDeb = substr((string) $epreuve->heure_debut, 0, 5);
                $hFin = substr((string) $epreuve->heure_fin, 0, 5);
                $idsSallesMob = $sallesMobilisees->keys()->all();

                foreach ($sallesMobilisees as $salleMob) {
                    if (($salleMob->heure_ouverture || $salleMob->heure_fermeture)
                        && ! $salleMob->estOuvertePlage($hDeb, $hFin)) {
                        $conflitsBloquants[] = "Épreuve #{$epreuve->code} : salle '{$salleMob->nom}' fermée sur cette plage horaire.";
                    }
                }

                $indispo = ScheduleAvailability::query()
                    ->indisponibles()
                    ->where(function ($q) use ($epreuve, $idsSallesMob) {
                        $q->whereIn('room_id', $idsSallesMob);
                        if ($epreuve->groupe_id) {
                            $q->orWhere('group_id', $epreuve->groupe_id);
                        }
                    })
                    ->where('day_of_week', $jourSemaine)
                    ->get()
                    ->first(fn ($p) => $p->chevauche($hDeb, $hFin) && $p->couvrePeriode($dateStr, $dateStr));

                if ($indispo !== null) {
                    $salleIndispo = $indispo->room_id !== null ? $sallesMobilisees->get((int) $indispo->room_id) : null;
                    $ressource = $salleIndispo !== null
                        ? "salle '{$salleIndispo->nom}'"
                        : 'groupe';
                    $conflitsBloquants[] = "Épreuve #{$epreuve->code} : {$ressource} indisponible sur ce créneau.";
                }

                foreach ($sallesMobilisees as $salleMob) {
                    $motifCours = $this->coursRegulierEnConflit(
                        $epreuve->groupe_id,
                        $salleMob->id,
                        $jourSemaine,
                        $hDeb,
                        $hFin
                    );

                    if ($motifCours !== null) {
                        $conflitsBloquants[] = "Épreuve #{$epreuve->code} : {$motifCours}";
                    }
                }
            }

            // 6. Correspondance stricte Matière ↔ Groupes ↔ Étudiants ↔ Examens
            $correspondance = app(CorrespondanceExamenService::class)
                ->analyserEpreuve($epreuve, exigerEligiblesPourPublication: false);

            if (! $correspondance['conforme']) {
                foreach ($correspondance['bloquants'] as $motifCorrespondance) {
                    $conflitsBloquants[] = "Épreuve #{$epreuve->code} : {$motifCorrespondance}";
                }
            }
        }

        // 5. Conflits d'interférence entre épreuves du même examen (groupe ou salle)
        $nbEpreuves = $epreuves->count();
        for ($i = 0; $i < $nbEpreuves; $i++) {
            $ep1 = $epreuves[$i];
            if (! $ep1->date || ! $ep1->heure_debut || ! $ep1->heure_fin) {
                continue;
            }
            $d1 = Carbon::parse($ep1->date)->format('Y-m-d');
            $hDeb1 = substr((string) $ep1->heure_debut, 0, 5);
            $hFin1 = substr((string) $ep1->heure_fin, 0, 5);
            $sallesEp1 = array_values(array_unique(array_filter([
                $ep1->salle_id ? (int) $ep1->salle_id : null,
                ...$ep1->candidats->pluck('salle_id')->filter()->map(fn ($id) => (int) $id)->all(),
            ])));

            for ($j = $i + 1; $j < $nbEpreuves; $j++) {
                $ep2 = $epreuves[$j];
                if (! $ep2->date || ! $ep2->heure_debut || ! $ep2->heure_fin) {
                    continue;
                }
                $d2 = Carbon::parse($ep2->date)->format('Y-m-d');
                if ($d1 !== $d2) {
                    continue;
                }
                $hDeb2 = substr((string) $ep2->heure_debut, 0, 5);
                $hFin2 = substr((string) $ep2->heure_fin, 0, 5);

                if ($this->chevaucheHeures($hDeb1, $hFin1, $hDeb2, $hFin2)) {
                    if ($ep1->groupe_id && $ep1->groupe_id === $ep2->groupe_id) {
                        $conflitsBloquants[] = "Collision groupe : épreuves #{$ep1->code} et #{$ep2->code} planifiées simultanément pour le même groupe.";
                    }
                    $sallesEp2 = array_values(array_unique(array_filter([
                        $ep2->salle_id ? (int) $ep2->salle_id : null,
                        ...$ep2->candidats->pluck('salle_id')->filter()->map(fn ($id) => (int) $id)->all(),
                    ])));
                    if (array_intersect($sallesEp1, $sallesEp2) !== []) {
                        $conflitsBloquants[] = "Collision salle : épreuves #{$ep1->code} et #{$ep2->code} assignées à la même salle sur le même créneau.";
                    }
                }
            }
        }

        $nbTotal = $epreuves->count();
        $penalites = ($sansSalle * 30) + ($sousSurveillees * 20) + (count($conflitsBloquants) * 10);
        $score = max(0, 100 - $penalites);
        $conforme = empty($conflitsBloquants) && $nbTotal > 0;

        return [
            'conforme' => $conforme,
            'score_conformite' => $score,
            'nb_epreuves' => $nbTotal,
            'epreuves_sans_salle' => $sansSalle,
            'epreuves_sous_surveillees' => $sousSurveillees,
            'conflits_bloquants' => array_values(array_unique($conflitsBloquants)),
        ];
    }

    /**
     * Génère 3 scénarios comparables d'ordonnancement des examens avec contraintes dures et souples.
     *
     * @param  array<string, mixed>  $options
     * @return array{
     *     examen_id: int,
     *     scenarios: array<string, array<string, mixed>>,
     *     comparaison: array<string, mixed>,
     *     recommandation: array<string, mixed>
     * }
     *
     * @throws DomainException
     */
    public function genererScenariosPlanning(Examen|SessionExamen|int $contexte, array $options = []): array
    {
        $examen = $contexte instanceof Examen
            ? $contexte
            : ($contexte instanceof SessionExamen
                ? $contexte->examens()->firstOrFail()
                : Examen::findOrFail($contexte));

        if ($examen->estCritique() && ! ($options['autoriser_ecrasement'] ?? false)) {
            throw new DomainException("La génération n'écrase pas un planning validé sans autorisation.");
        }

        $epreuves = $examen->epreuves()
            ->where('statut', '!=', 'cancelled')
            ->with(['matiere', 'groupe', 'salle'])
            ->get();

        $ratio = (float) ($options['ratio_espacement'] ?? self::RATIO_CAPACITE_EXAMEN_DEFAUT);
        $faculteId = $examen->faculteId();

        $salles = Room::where('actif', true)
            ->whereNotNull('capacite')
            ->where('capacite', '>', 0)
            ->when($faculteId, fn ($q) => $q->where(fn ($sub) => $sub->where('faculte_id', $faculteId)->orWhereNull('faculte_id')))
            ->get();

        // Le périmètre facultaire d'un enseignant passe par son département :
        // la table `teachers` ne porte pas de colonne `faculte_id`.
        $enseignants = Teacher::where('actif', true)
            ->when($faculteId, fn ($q) => $q->where(
                fn ($sub) => $sub
                    ->whereHas('departement', fn ($d) => $d->where('faculte_id', $faculteId))
                    ->orWhereNull('departement_id')
            ))
            ->get();

        $dateDebut = $options['date_debut']
            ?? ($examen->session?->date_debut ? Carbon::parse($examen->session->date_debut)->format('Y-m-d') : now()->addDays(7)->format('Y-m-d'));
        $dateFin = $options['date_fin']
            ?? ($examen->session?->date_fin ? Carbon::parse($examen->session->date_fin)->format('Y-m-d') : Carbon::parse($dateDebut)->addDays(14)->format('Y-m-d'));

        $dates = [];
        $cur = Carbon::parse($dateDebut);
        $fin = Carbon::parse($dateFin);
        while ($cur->lte($fin)) {
            if (! $cur->isSunday()) {
                $dates[] = $cur->format('Y-m-d');
            }
            $cur->addDay();
        }

        if (empty($dates)) {
            $dates = [$dateDebut];
        }

        $plages = $options['plages_horaires'] ?? [
            ['debut' => '08:30:00', 'fin' => '10:30:00', 'confort' => 95, 'code' => 'P1'],
            ['debut' => '11:00:00', 'fin' => '13:00:00', 'confort' => 85, 'code' => 'P2'],
            ['debut' => '14:30:00', 'fin' => '16:30:00', 'confort' => 80, 'code' => 'P3'],
        ];

        $scenarios = [];
        foreach ([self::SCENARIO_ETALE, self::SCENARIO_COMPACT, self::SCENARIO_AMPHI] as $type) {
            $scenarios[$type] = $this->resoudreScenario(
                $type,
                $epreuves,
                $salles,
                $enseignants,
                $dates,
                $plages,
                $ratio,
                $options
            );
        }

        $comparaison = $this->comparerScenarios($scenarios);

        return [
            'examen_id' => $examen->id,
            'scenarios' => $scenarios,
            'comparaison' => $comparaison['scenarios'],
            'recommandation' => $comparaison['recommandation'],
        ];
    }

    /**
     * Résout un scénario sous contraintes dures et préférences souples spécifiques.
     *
     * @param  Collection<int, Epreuve>  $epreuves
     * @param  Collection<int, Room>  $salles
     * @param  Collection<int, Teacher>  $enseignants
     * @param  array<int, string>  $dates
     * @param  array<int, array{debut: string, fin: string, confort: int, code: string}>  $plages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function resoudreScenario(
        string $scenarioType,
        Collection $epreuves,
        Collection $salles,
        Collection $enseignants,
        array $dates,
        array $plages,
        float $ratio,
        array $options
    ): array {
        $placements = [];
        $anomalies = [];

        $sallesOrdonnees = match ($scenarioType) {
            self::SCENARIO_AMPHI => $salles->sortByDesc('capacite')->values(),
            default => $salles->sortBy('capacite')->values(),
        };

        $epreuvesOrdonnees = $epreuves->sortBy(fn ($e) => [$e->groupe_id ?? 0, $e->matiere?->code ?? $e->code])->values();

        foreach ($epreuvesOrdonnees as $epreuve) {
            $effectif = $epreuve->capacite_necessaire > 0
                ? (int) $epreuve->capacite_necessaire
                : (int) ($epreuve->groupe?->capacite ?? 25);

            $sallesCompatibles = $sallesOrdonnees->filter(fn ($s) => $this->capaciteUtileExamen($s, $ratio) >= $effectif);
            if ($sallesCompatibles->isEmpty()) {
                $maxUtile = $salles->map(fn ($s) => $this->capaciteUtileExamen($s, $ratio))->max() ?? 0;
                $anomalies[] = "Contrainte impossible pour l'épreuve #{$epreuve->code} ({$epreuve->matiere?->nom}) : salles sous-capacitaires avec espacement ({$effectif} requis avec espacement 50%, max disponible : {$maxUtile} places).";

                continue;
            }

            $datesCandidates = $dates;
            if ($scenarioType === self::SCENARIO_ETALE) {
                $groupeId = $epreuve->groupe_id;
                usort($datesCandidates, function ($dA, $dB) use ($placements, $groupeId) {
                    $countA = count(array_filter($placements, fn ($p) => $p['date'] === $dA && $p['groupe_id'] === $groupeId));
                    $countB = count(array_filter($placements, fn ($p) => $p['date'] === $dB && $p['groupe_id'] === $groupeId));
                    if ($countA !== $countB) {
                        return $countA <=> $countB;
                    }

                    return strcmp($dA, $dB);
                });
            }

            $place = false;
            foreach ($datesCandidates as $dateStr) {
                if ($scenarioType === self::SCENARIO_ETALE && $epreuve->groupe_id) {
                    $dejaCeJour = array_any($placements, fn ($p) => $p['date'] === $dateStr && $p['groupe_id'] === $epreuve->groupe_id);
                    if ($dejaCeJour && count($datesCandidates) > count($epreuvesOrdonnees)) {
                        continue;
                    }
                }

                foreach ($plages as $plage) {
                    foreach ($sallesCompatibles as $salle) {
                        $check = $this->verifierCreneauValide(
                            $epreuve,
                            $salle,
                            $dateStr,
                            $plage['debut'],
                            $plage['fin'],
                            $ratio,
                            $placements
                        );

                        if ($check['valide']) {
                            $surveillantsAffectes = [];
                            $minSurv = $salle->min_surveillants ?? 1;
                            $jourSemaine = strtolower(Carbon::parse($dateStr)->format('l'));

                            foreach ($enseignants as $ens) {
                                if (count($surveillantsAffectes) >= $minSurv) {
                                    break;
                                }

                                $occupeScenario = array_any($placements, function ($p) use ($ens, $dateStr, $plage) {
                                    return $p['date'] === $dateStr
                                        && $this->chevaucheHeures($plage['debut'], $plage['fin'], $p['heure_debut'], $p['heure_fin'])
                                        && in_array($ens->id, $p['surveillants'] ?? [], true);
                                });
                                if ($occupeScenario) {
                                    continue;
                                }

                                $indispo = ScheduleAvailability::query()
                                    ->indisponibles()
                                    ->where('teacher_id', $ens->id)
                                    ->where('day_of_week', $jourSemaine)
                                    ->get()
                                    ->first(fn ($p) => $p->chevauche(substr($plage['debut'], 0, 5), substr($plage['fin'], 0, 5)) && $p->couvrePeriode($dateStr, $dateStr));
                                if ($indispo !== null) {
                                    continue;
                                }

                                $surveillantsAffectes[] = $ens->id;
                            }

                            $placements[] = [
                                'epreuve_id' => $epreuve->id,
                                'code' => $epreuve->code,
                                'matiere_id' => $epreuve->matiere_id,
                                'matiere_code' => $epreuve->matiere?->code,
                                'matiere_nom' => $epreuve->matiere?->nom,
                                'groupe_id' => $epreuve->groupe_id,
                                'groupe_nom' => $epreuve->groupe?->nom,
                                'salle_id' => $salle->id,
                                'salle_code' => $salle->code,
                                'salle_nom' => $salle->nom,
                                'date' => $dateStr,
                                'heure_debut' => $plage['debut'],
                                'heure_fin' => $plage['fin'],
                                'score_confort' => $plage['confort'],
                                'capacite_utile' => $this->capaciteUtileExamen($salle, $ratio),
                                'effectif' => $effectif,
                                'surveillants' => $surveillantsAffectes,
                            ];

                            $place = true;
                            break 3;
                        }
                    }
                }
            }

            if (! $place) {
                $anomalies[] = "Contrainte impossible pour l'épreuve #{$epreuve->code} ({$epreuve->matiere?->nom}) : aucun créneau sans conflit disponible sur la période du {$dates[0]} au ".(end($dates) ?: $dates[0]).'.';
            }
        }

        $totalEpreuves = $epreuves->count();
        $placees = count($placements);
        $nonPlacees = $totalEpreuves - $placees;
        $nbConflits = count($anomalies);
        $estValide = ($nbConflits === 0 && $totalEpreuves > 0 && $placees === $totalEpreuves);

        $joursMobilises = count(array_unique(array_column($placements, 'date')));
        $tauxMoyen = $placees > 0
            ? round(collect($placements)->avg(fn ($p) => ($p['capacite_utile'] > 0 ? ($p['effectif'] / $p['capacite_utile']) * 100 : 0)), 1)
            : 0.0;

        $baseScore = $placees > 0 ? (int) round(collect($placements)->avg('score_confort') ?? 80) : 0;
        if (! $estValide) {
            $score = max(0, 100 - ($nbConflits * 30));
        } else {
            $score = match ($scenarioType) {
                self::SCENARIO_ETALE => min(100, $baseScore + 5),
                self::SCENARIO_COMPACT => min(100, $baseScore),
                self::SCENARIO_AMPHI => min(100, $baseScore + 3),
            };
        }

        return [
            'scenario_id' => $scenarioType,
            'nom' => self::SCENARIOS[$scenarioType] ?? $scenarioType,
            'est_valide' => $estValide,
            'score_qualite' => $score,
            'nb_conflits_durs' => $nbConflits,
            'anomalies_impossibles' => $anomalies,
            'placements' => $placements,
            'metriques' => [
                'total_epreuves' => $totalEpreuves,
                'epreuves_placees' => $placees,
                'epreuves_non_placees' => $nonPlacees,
                'jours_mobilises' => $joursMobilises,
                'taux_remplissage_moyen' => $tauxMoyen,
            ],
        ];
    }

    /**
     * Contrôle strict des contraintes dures pour un créneau candidat.
     *
     * @param  array<int, array<string, mixed>>  $placementsActuels
     * @param  array<int, int>  $ignorerEpreuveIds  Épreuves déplacées par le
     *                                              scénario en cours : leurs
     *                                              anciennes positions ne
     *                                              doivent pas être vues comme
     *                                              des conflits persistants.
     * @return array{valide: bool, motif?: string}
     */
    public function verifierCreneauValide(
        Epreuve $epreuve,
        Room $salle,
        string $dateStr,
        string $heureDebut,
        string $heureFin,
        float $ratio,
        array $placementsActuels,
        array $ignorerEpreuveIds = [],
        bool $verrouillerConflit = false
    ): array {
        if (! $salle->actif) {
            return ['valide' => false, 'motif' => 'Salle inactive.'];
        }

        if (($salle->heure_ouverture || $salle->heure_fermeture)
            && ! $salle->estOuvertePlage(substr($heureDebut, 0, 5), substr($heureFin, 0, 5))) {
            return ['valide' => false, 'motif' => 'Salle fermée sur cette plage horaire.'];
        }

        $effectif = $epreuve->capacite_necessaire > 0
            ? (int) $epreuve->capacite_necessaire
            : (int) ($epreuve->groupe?->capacite ?? 25);

        if ($this->capaciteUtileExamen($salle, $ratio) < $effectif) {
            return ['valide' => false, 'motif' => 'Capacité utile insuffisante avec espacement.'];
        }

        $groupeId = $epreuve->groupe_id;

        // Collision dans les placements actuels du scénario
        foreach ($placementsActuels as $p) {
            if ($p['date'] === $dateStr && $this->chevaucheHeures($heureDebut, $heureFin, $p['heure_debut'], $p['heure_fin'])) {
                if ($groupeId && $p['groupe_id'] === $groupeId) {
                    return ['valide' => false, 'motif' => 'Collision groupe : déjà une épreuve programmée sur ce créneau.'];
                }
                if ($p['salle_id'] === $salle->id) {
                    return ['valide' => false, 'motif' => 'Collision salle : déjà occupée sur ce créneau.'];
                }
            }
        }

        // Collision en base de données avec des épreuves existantes. Les
        // épreuves re-planifiées par le scénario sont ignorées : leurs
        // anciennes positions vont être remplacées dans la même transaction.
        $epreuveId = $epreuve->id;
        $conflitDb = Epreuve::whereDate('date', $dateStr)
            ->where('statut', '!=', 'cancelled')
            ->when($epreuveId, fn ($q) => $q->where('id', '!=', $epreuveId))
            ->when($ignorerEpreuveIds !== [], fn ($q) => $q->whereNotIn('id', $ignorerEpreuveIds))
            ->where(function ($q) use ($heureDebut, $heureFin) {
                $q->where('heure_debut', '<', $heureFin)
                    ->where('heure_fin', '>', $heureDebut);
            })
            ->where(function ($q) use ($salle, $groupeId) {
                $q->where('salle_id', $salle->id);
                if ($groupeId) {
                    $q->orWhere('groupe_id', $groupeId);
                }
            })
            // Lecture courante (et non instantané REPEATABLE READ) quand le
            // contrôle précède immédiatement une écriture : garantit que
            // l'affectation concurrente déjà validée est bien vue.
            ->when($verrouillerConflit, fn ($q) => $q->lockForUpdate())
            ->first();

        if ($conflitDb) {
            return ['valide' => false, 'motif' => 'Conflit en base avec l\'épreuve #'.$conflitDb->code];
        }

        // Indisponibilités confirmées (ScheduleAvailability)
        $jourSemaine = strtolower(Carbon::parse($dateStr)->format('l'));
        $hDeb = substr($heureDebut, 0, 5);
        $hFin = substr($heureFin, 0, 5);

        $indispo = ScheduleAvailability::query()
            ->indisponibles()
            ->where(function ($q) use ($salle, $groupeId) {
                $q->where('room_id', $salle->id);
                if ($groupeId) {
                    $q->orWhere('group_id', $groupeId);
                }
            })
            ->where('day_of_week', $jourSemaine)
            ->get()
            ->first(fn ($p) => $p->chevauche($hDeb, $hFin) && $p->couvrePeriode($dateStr, $dateStr));

        if ($indispo !== null) {
            return ['valide' => false, 'motif' => "Ressource indisponible ({$indispo->reason})."];
        }

        // Cours régulier planifié (ScheduleSlot) : salle ET groupe.
        $motifCours = $this->coursRegulierEnConflit($groupeId, $salle->id, $jourSemaine, $hDeb, $hFin);

        if ($motifCours !== null) {
            return ['valide' => false, 'motif' => $motifCours];
        }

        return ['valide' => true];
    }

    /**
     * Détecte un cours régulier non annulé qui chevauche la plage, soit
     * parce qu'il occupe la salle, soit parce qu'il concerne le groupe.
     */
    private function coursRegulierEnConflit(
        ?int $groupeId,
        ?int $salleId,
        string $jourSemaine,
        string $hDeb,
        string $hFin
    ): ?string {
        if ($salleId === null && $groupeId === null) {
            return null;
        }

        $slot = ScheduleSlot::query()
            ->where('day_of_week', $jourSemaine)
            ->where('status', '!=', 'cancelled')
            ->where(function ($q) use ($groupeId, $salleId) {
                if ($salleId !== null) {
                    $q->where('room_id', $salleId);
                }
                if ($groupeId !== null) {
                    $q->orWhereIn(
                        'schedule_id',
                        Schedule::where('groupe_id', $groupeId)->where('actif', true)->select('id')
                    );
                }
            })
            ->get()
            ->first(fn (ScheduleSlot $slot) => $this->chevaucheStocke(
                $slot->start_time,
                $slot->end_time,
                $hDeb,
                $hFin
            ));

        if ($slot === null) {
            return null;
        }

        return $salleId !== null && (int) $slot->room_id === $salleId
            ? 'Salle occupée par un cours régulier planifié.'
            : 'Le groupe a un cours régulier planifié sur ce créneau.';
    }

    /**
     * Re-vérifie un surveillant fourni par un scénario : existence,
     * activation, périmètre faculté, indisponibilité déclarée, surveillance
     * concurrente et cours régulier.
     */
    private function verifierSurveillantDisponible(
        int $teacherId,
        string $dateStr,
        string $hDeb,
        string $hFin,
        int $epreuveId,
        ?int $faculteId,
        array $placementsActuels = [],
        array $ignorerEpreuveIds = []
    ): void {
        /** @var Teacher|null $enseignant */
        $enseignant = Teacher::find($teacherId);
        if (! $enseignant || ! $enseignant->actif) {
            throw new DomainException("Surveillant #{$teacherId} inexistant ou inactif.");
        }

        if ($faculteId !== null && $enseignant->faculteId() !== null
            && $enseignant->faculteId() !== $faculteId) {
            throw new DomainException("Surveillant #{$teacherId} hors du périmètre de la faculté.");
        }

        $jourSemaine = strtolower(Carbon::parse($dateStr)->format('l'));

        $indispo = ScheduleAvailability::query()
            ->indisponibles()
            ->where('teacher_id', $teacherId)
            ->where('day_of_week', $jourSemaine)
            ->get()
            ->first(fn (ScheduleAvailability $p) => $p->chevauche(
                substr($hDeb, 0, 5),
                substr($hFin, 0, 5)
            ) && $p->couvrePeriode($dateStr, $dateStr));

        if ($indispo !== null) {
            throw new DomainException("Surveillant #{$teacherId} indisponible sur ce créneau.");
        }

        $dejaMobilise = Surveillance::where('teacher_id', $teacherId)
            ->where('epreuve_id', '!=', $epreuveId)
            ->when($ignorerEpreuveIds !== [], fn ($q) => $q->whereNotIn('epreuve_id', $ignorerEpreuveIds))
            ->where('statut', '!=', 'annule')
            ->whereHas('epreuve', function ($q) use ($dateStr, $hDeb, $hFin) {
                $q->whereDate('date', $dateStr)
                    ->where('statut', '!=', 'cancelled')
                    ->where('heure_debut', '<', $hFin)
                    ->where('heure_fin', '>', $hDeb);
            })
            ->exists();

        if ($dejaMobilise) {
            throw new DomainException("Surveillant #{$teacherId} déjà mobilisé sur un autre examen à ce créneau.");
        }

        foreach ($placementsActuels as $placement) {
            if (($placement['date'] ?? null) === $dateStr
                && $this->chevaucheHeures($hDeb, $hFin, (string) ($placement['heure_debut'] ?? ''), (string) ($placement['heure_fin'] ?? ''))
                && in_array($teacherId, $placement['surveillants'] ?? [], true)) {
                throw new DomainException("Surveillant #{$teacherId} affecté à deux épreuves simultanées du scénario.");
            }
        }

        $cours = ScheduleSlot::where('teacher_id', $teacherId)
            ->where('day_of_week', $jourSemaine)
            ->where('status', '!=', 'cancelled')
            ->get()
            ->contains(fn (ScheduleSlot $slot) => $this->chevaucheStocke(
                $slot->start_time,
                $slot->end_time,
                $hDeb,
                $hFin
            ));

        if ($cours) {
            throw new DomainException("Surveillant #{$teacherId} en cours régulier sur ce créneau.");
        }
    }

    /**
     * Normalise et valide une heure `HH:MM` ou `HH:MM:SS`.
     */
    private function normaliserHeure(mixed $heure): ?string
    {
        if (! is_string($heure) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $heure) !== 1) {
            return null;
        }

        return strlen($heure) === 5 ? $heure.':00' : $heure;
    }

    private function chevaucheHeures(string $debutA, string $finA, string $debutB, string $finB): bool
    {
        return $debutA < $finB && $debutB < $finA;
    }

    /**
     * Chevauchement entre une plage candidate (HH:MM) et des horaires
     * stockés qui peuvent porter des secondes. La comparaison se fait sur
     * cinq caractères pour éviter qu'une borne exacte (`10:00` vs
     * `10:00:00`) soit vue comme un chevauchement.
     */
    private function chevaucheStocke(mixed $debutStocke, mixed $finStocke, string $hDeb, string $hFin): bool
    {
        return substr((string) $debutStocke, 0, 5) < $hFin
            && $hDeb < substr((string) $finStocke, 0, 5);
    }

    /**
     * Compare plusieurs scénarios et formule une recommandation argumentée.
     *
     * @param  array<string, array<string, mixed>>  $scenarios
     * @return array{
     *     scenarios: array<string, array<string, mixed>>,
     *     recommandation: array{
     *         scenario_id: ?string,
     *         nom: ?string,
     *         score_qualite: ?int,
     *         motif: string
     *     }
     * }
     */
    public function comparerScenarios(array $scenarios): array
    {
        $tableau = [];
        $meilleur = null;
        $scoreMax = -1;

        foreach ($scenarios as $key => $sc) {
            $valide = $sc['est_valide'] ?? false;
            $score = $sc['score_qualite'] ?? 0;

            $tableau[$key] = [
                'id' => $sc['scenario_id'] ?? $key,
                'nom' => $sc['nom'] ?? $key,
                'est_valide' => $valide,
                'score_qualite' => $score,
                'nb_conflits_durs' => $sc['nb_conflits_durs'] ?? 0,
                'total_epreuves' => $sc['metriques']['total_epreuves'] ?? 0,
                'epreuves_placees' => $sc['metriques']['epreuves_placees'] ?? 0,
                'epreuves_non_placees' => $sc['metriques']['epreuves_non_placees'] ?? 0,
                'jours_mobilises' => $sc['metriques']['jours_mobilises'] ?? 0,
                'taux_remplissage_moyen' => $sc['metriques']['taux_remplissage_moyen'] ?? 0,
            ];

            if ($valide && $score > $scoreMax) {
                $scoreMax = $score;
                $meilleur = $sc;
            }
        }

        return [
            'scenarios' => $tableau,
            'recommandation' => [
                'scenario_id' => $meilleur['scenario_id'] ?? null,
                'nom' => $meilleur['nom'] ?? null,
                'score_qualite' => $scoreMax >= 0 ? $scoreMax : null,
                'motif' => $meilleur !== null
                    ? "Scénario « {$meilleur['nom']} » optimal : 0 conflit dur et score maximal de {$scoreMax}/100."
                    : 'Aucun scénario 100% réalisable identifié. Des contraintes dures doivent être ajustées.',
            ],
        ];
    }

    /**
     * Applique un scénario validé sur l'examen officiel de manière transactionnelle et tracée.
     *
     * @param  array<string, mixed>  $scenarioData
     * @return array{
     *     succes: bool,
     *     scenario_id: string,
     *     examen_id: int,
     *     statut_examen: string,
     *     epreuves_mises_a_jour: int,
     *     surveillances_affectees: int
     * }
     *
     * @throws DomainException
     */
    public function appliquerScenario(
        Examen|SessionExamen|int $contexte,
        string $scenarioId,
        array $scenarioData,
        ?int $userId = null,
        bool $forcer = false
    ): array {
        $examen = $contexte instanceof Examen
            ? $contexte
            : ($contexte instanceof SessionExamen
                ? $contexte->examens()->firstOrFail()
                : Examen::findOrFail($contexte));

        if (! ($scenarioData['est_valide'] ?? false) || ($scenarioData['nb_conflits_durs'] ?? 0) > 0) {
            throw new DomainException("Impossible d'appliquer un scénario invalide : contraintes dures violées.");
        }

        if ($examen->estCritique() && ! $forcer) {
            throw new DomainException("La génération n'écrase pas un planning validé sans autorisation.");
        }

        return DB::transaction(function () use ($examen, $scenarioId, $scenarioData, $userId, $forcer) {
            /** @var Examen $verrouille */
            $verrouille = Examen::whereKey($examen->id)->lockForUpdate()->firstOrFail();

            $placements = $scenarioData['placements'] ?? null;
            if (! is_array($placements) || $placements === []) {
                throw new DomainException('Scénario sans placement : aucun ordonnancement à appliquer.');
            }

            // §7.3 / DM-05 — verrouillage des ressources PARTAGÉES AVANT
            // toute lecture non verrouillée. Sous REPEATABLE READ, la
            // première lecture cohérente fige la vue de la transaction :
            // si elle précède l'obtention du verrou, le scénario perdant ne
            // verrait jamais l'affectation validée par le gagnant (TOCTOU).
            // Les identifiants sont verrouillés en ordre croissant dans
            // toutes les transactions pour exclure tout interblocage.
            $sallesDemandees = collect($placements)
                ->filter(fn ($p): bool => is_array($p) && (int) ($p['salle_id'] ?? 0) > 0)
                ->map(fn ($p): int => (int) $p['salle_id'])
                ->unique()
                ->sort()
                ->values()
                ->all();

            /** @var Collection<int, Room> $sallesVerrouillees */
            $sallesVerrouillees = $sallesDemandees === []
                ? collect()
                : Room::whereIn('id', $sallesDemandees)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

            $enseignantsDemandes = collect($placements)
                ->filter(fn ($p): bool => is_array($p) && is_array($p['surveillants'] ?? null))
                ->flatMap(fn ($p): array => array_map('intval', $p['surveillants']))
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->sort()
                ->values()
                ->all();

            if ($enseignantsDemandes !== []) {
                Teacher::whereIn('id', $enseignantsDemandes)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
            }

            /** @var Collection<int, Epreuve> $epreuvesExamen */
            $epreuvesExamen = Epreuve::where('examen_id', $verrouille->id)
                ->where('statut', '!=', 'cancelled')
                ->with(['groupe', 'salle'])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($epreuvesExamen->isEmpty()) {
                throw new DomainException('Aucune épreuve planifiable pour cet examen.');
            }

            // Les placements soumis ne sont jamais crus : le scénario client
            // est revalidé intégralement contre l'état serveur (appartenance,
            // salle, capacité, collisions, disponibilités, cours, surveillants).
            $idsReplaces = [];
            foreach ($placements as $placement) {
                $id = is_array($placement) ? (int) ($placement['epreuve_id'] ?? 0) : 0;
                if ($id > 0) {
                    $idsReplaces[] = $id;
                }
            }
            $idsReplaces = array_values(array_unique($idsReplaces));

            $vus = [];
            $normalises = [];
            $placementsValides = [];
            $faculteId = $verrouille->faculteId();

            foreach ($placements as $index => $placement) {
                if (! is_array($placement)) {
                    throw new DomainException("Placement #{$index} refusé : structure invalide.");
                }

                $epreuveId = (int) ($placement['epreuve_id'] ?? 0);
                if (! $epreuvesExamen->has($epreuveId)) {
                    throw new DomainException("Placement refusé : l'épreuve #{$epreuveId} n'appartient pas à cet examen.");
                }

                if (isset($vus[$epreuveId])) {
                    throw new DomainException("Placement refusé : l'épreuve #{$epreuveId} est présente plusieurs fois dans le scénario.");
                }
                $vus[$epreuveId] = true;

                /** @var Epreuve $epreuve */
                $epreuve = $epreuvesExamen->get($epreuveId);

                if ($epreuve->statut === 'locked' && ! $forcer) {
                    throw new DomainException("Placement refusé : l'épreuve #{$epreuve->code} est verrouillée.");
                }

                $dateBrute = $placement['date'] ?? null;
                if (! is_string($dateBrute) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateBrute) !== 1) {
                    throw new DomainException("Placement refusé pour l'épreuve #{$epreuve->code} : date invalide (AAAA-MM-JJ attendu).");
                }

                [$annee, $mois, $jour] = array_map('intval', explode('-', $dateBrute));
                if (! checkdate($mois, $jour, $annee)) {
                    throw new DomainException("Placement refusé pour l'épreuve #{$epreuve->code} : date inexistante ({$dateBrute}).");
                }
                $dateStr = $dateBrute;

                $hDeb = $this->normaliserHeure($placement['heure_debut'] ?? null);
                $hFin = $this->normaliserHeure($placement['heure_fin'] ?? null);
                if ($hDeb === null || $hFin === null) {
                    throw new DomainException("Placement refusé pour l'épreuve #{$epreuve->code} : horaires invalides.");
                }
                if ($hDeb >= $hFin) {
                    throw new DomainException("Placement refusé pour l'épreuve #{$epreuve->code} : début ({$hDeb}) postérieur ou égal à la fin ({$hFin}).");
                }

                $salleId = (int) ($placement['salle_id'] ?? 0);
                // La salle provient de la collection déjà verrouillée : la
                // relecture ne peut pas observer un état postérieur au
                // verrou.
                /** @var Room|null $salle */
                $salle = $sallesVerrouillees->get($salleId);
                if (! $salle) {
                    throw new DomainException("Placement refusé pour l'épreuve #{$epreuve->code} : salle #{$salleId} inexistante.");
                }
                if (! $salle->actif) {
                    throw new DomainException("Placement refusé pour l'épreuve #{$epreuve->code} : salle '{$salle->nom}' inactive.");
                }
                if ($salle->faculte_id !== null && $faculteId !== null
                    && (int) $salle->faculte_id !== $faculteId) {
                    throw new DomainException("Placement refusé : la salle '{$salle->nom}' appartient à une autre faculté.");
                }

                $controle = $this->verifierCreneauValide(
                    $epreuve,
                    $salle,
                    $dateStr,
                    $hDeb,
                    $hFin,
                    self::RATIO_CAPACITE_EXAMEN_DEFAUT,
                    $placementsValides,
                    $idsReplaces,
                    true
                );

                if (! $controle['valide']) {
                    $motif = $controle['motif'] ?? 'contrainte dure violée';
                    throw new DomainException("Placement refusé pour l'épreuve #{$epreuve->code} : {$motif}");
                }

                $surveillants = $placement['surveillants'] ?? [];
                if (! is_array($surveillants)) {
                    throw new DomainException("Placement refusé pour l'épreuve #{$epreuve->code} : liste de surveillants invalide.");
                }

                $surveillants = array_values(array_unique(array_filter(
                    array_map(static fn ($id): int => (int) $id, $surveillants),
                    static fn (int $id): bool => $id > 0
                )));

                foreach ($surveillants as $teacherId) {
                    $this->verifierSurveillantDisponible(
                        $teacherId,
                        $dateStr,
                        $hDeb,
                        $hFin,
                        $epreuve->id,
                        $faculteId,
                        $placementsValides,
                        $idsReplaces
                    );
                }

                $placementsValides[] = [
                    'date' => $dateStr,
                    'heure_debut' => $hDeb,
                    'heure_fin' => $hFin,
                    'groupe_id' => $epreuve->groupe_id,
                    'salle_id' => $salle->id,
                    'surveillants' => $surveillants,
                ];

                $normalises[] = [
                    'epreuve' => $epreuve,
                    'date' => $dateStr,
                    'heure_debut' => $hDeb,
                    'heure_fin' => $hFin,
                    'salle_id' => $salle->id,
                    'surveillants' => $surveillants,
                ];
            }

            $manquantes = $epreuvesExamen->keys()->diff(array_keys($vus));
            if ($manquantes->isNotEmpty()) {
                throw new DomainException(
                    'Scénario incomplet : '.$manquantes->count()
                    .' épreuve(s) de cet examen ne sont pas ordonnancées.'
                );
            }

            $misesAJour = 0;
            $surveillancesCreees = 0;

            foreach ($normalises as $item) {
                /** @var Epreuve $epreuve */
                $epreuve = $item['epreuve'];

                $epreuve->update([
                    'date' => $item['date'],
                    'heure_debut' => $item['heure_debut'],
                    'heure_fin' => $item['heure_fin'],
                    'salle_id' => $item['salle_id'],
                    'statut' => 'scheduled',
                ]);
                $misesAJour++;

                foreach ($item['surveillants'] as $idx => $teacherId) {
                    Surveillance::updateOrCreate(
                        [
                            'epreuve_id' => $epreuve->id,
                            'teacher_id' => $teacherId,
                        ],
                        [
                            'salle_id' => $item['salle_id'],
                            'role' => $idx === 0 ? 'principal' : 'surveillant',
                            'statut' => 'assigne',
                        ]
                    );
                    $surveillancesCreees++;
                }
            }

            if (in_array($verrouille->statut, ['draft', 'planned'], true)) {
                $verrouille->update(['statut' => 'scheduled']);
            }

            IntegrationLog::tracer(
                'evaluation',
                'internal',
                'exam.schedule.scenario_applied',
                'completed',
                json_encode([
                    'scenario_id' => $scenarioId,
                    'epreuves_mises_a_jour' => $misesAJour,
                    'surveillances_affectees' => $surveillancesCreees,
                    'user_id' => $userId,
                ]),
                200,
                null,
                null,
                (string) Str::uuid(),
                'EXAM-'.$verrouille->id
            );

            EvaluationChanged::dispatch('examen.planning_applique', $verrouille->id);

            return [
                'succes' => true,
                'scenario_id' => $scenarioId,
                'examen_id' => $verrouille->id,
                'statut_examen' => $verrouille->statut,
                'epreuves_mises_a_jour' => $misesAJour,
                'surveillances_affectees' => $surveillancesCreees,
            ];
        });
    }
}
