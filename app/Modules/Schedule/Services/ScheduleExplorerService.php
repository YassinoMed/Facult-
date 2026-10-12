<?php

namespace App\Modules\Schedule\Services;

use App\Models\User;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Exploration multi-axes des emplois du temps : recherche combinée,
 * disponibilités, charge, conflits, occupation. Tout est calculé côté
 * serveur sur les données réelles, scopé faculté, caché avec version
 * (invalidation automatique via max(updated_at)).
 */
class ScheduleExplorerService
{
    /** Heures ouvrées pour la recherche de créneaux (pas de 30 min). */
    public const HEURE_OUVERTURE = '08:00';

    public const HEURE_FERMETURE = '18:00';

    public function __construct(
        private readonly ScheduleConflictService $conflits,
    ) {}

    /**
     * Version du cache : toute mutation de créneau/planning/exception
     * change la clé (invalidation sans tags, compatible driver file).
     */
    public function version(): string
    {
        $slot = ScheduleSlot::max('updated_at');
        $planning = Schedule::max('updated_at');

        return md5((string) $slot.'|'.(string) $planning);
    }

    /**
     * Créneaux filtrés multi-axes, scopés au périmètre de l'utilisateur.
     * Le cache ne stocke que des IDs (le durcissement
     * `cache.serializable_classes=false` interdit les objets sérialisés) ;
     * les modèles sont réhydratés en une requête, ordre préservé.
     *
     * @param  array<string, mixed>  $filtres
     * @return EloquentCollection<int, ScheduleSlot>
     */
    public function creneauxFiltres(User $user, array $filtres): EloquentCollection
    {
        $faculteId = $user->faculteRestreinteId();
        // v2 : la v1 stockait des modèles hydratés, interdits par
        // `cache.serializable_classes=false` (anciennes entrées ignorées).
        $cle = 'schedule.explorer.v2.'.md5(json_encode([$faculteId, $filtres, $this->version()]));

        /** @var array<int, int> $ids */
        $ids = Cache::remember($cle, 600, fn () => $this->requeteCreneaux($faculteId, $filtres, true)
            ->pluck('schedule_slots.id')->all());

        if ($ids === []) {
            return new EloquentCollection;
        }

        $ordre = array_flip($ids);

        return ScheduleSlot::with(['matiere', 'teacher', 'room', 'schedule.groupe', 'schedule.anneeUniversitaire'])
            ->whereIn('schedule_slots.id', $ids)->get()
            ->sortBy(fn (ScheduleSlot $slot) => $ordre[$slot->id] ?? PHP_INT_MAX)
            ->values();
    }

    /**
     * Disponibilité un jour/intervalle : chaque enseignant/salle marqué
     * libre ou occupé (conflits réels, pas d'estimation).
     *
     * @return array{enseignants: Collection, salles: Collection}
     */
    public function disponibilites(string $jour, string $debut, string $fin, ?int $faculteId): array
    {
        $slots = $this->requeteCreneaux($faculteId, ['jour' => $jour])->get();

        $enseignants = Teacher::where('actif', true)->orderBy('nom')->get()->map(function (Teacher $t) use ($slots, $debut, $fin) {
            $occupe = $slots->where('teacher_id', $t->id)->first(fn ($s) => $s->chevauche($debut, $fin));

            return [
                'id' => $t->id, 'nom' => $t->nomComplet(), 'libre' => $occupe === null,
                'occupe_par' => $occupe !== null ? $occupe->matiere->nom.' · '.$occupe->schedule->groupe->code : null,
            ];
        });

        $salles = Room::where('actif', true)->orderBy('code')->get()->map(function (Room $s) use ($slots, $debut, $fin) {
            $occupe = $slots->where('room_id', $s->id)->first(fn ($s2) => $s2->chevauche($debut, $fin));

            return [
                'id' => $s->id, 'code' => $s->code, 'capacite' => $s->capacite,
                'libre' => $occupe === null,
                'occupe_par' => $occupe !== null ? $occupe->matiere->nom.' · '.$occupe->schedule->groupe->code : null,
            ];
        });

        return ['enseignants' => $enseignants, 'salles' => $salles];
    }

    /**
     * Salles libres + capacité minimale, triées par capacité croissante.
     *
     * @return Collection<int, Room>
     */
    public function sallesLibres(string $jour, string $debut, string $fin, int $capaciteMin): Collection
    {
        $occupes = $this->requeteCreneaux(null, ['jour' => $jour])->get()
            ->filter(fn ($s) => $s->room_id !== null && $s->chevauche($debut, $fin))
            ->map(fn ($s) => $s->room_id)->unique()->all();

        return Room::where('actif', true)
            ->where('capacite', '>=', $capaciteMin)
            ->whereNotIn('id', $occupes)
            ->orderBy('capacite')->get();
    }

    /**
     * Créneaux libres (jour + début→fin) pour groupe + enseignant (+ salle
     * optionnelle) : grille 08h-18h au pas de 30 min, durée demandée,
     * contraintes réelles vérifiées (conflits + capacité).
     *
     * @return array<int, array{jour: string, debut: string, fin: string}>
     */
    public function creneauxLibres(int $groupeId, ?int $teacherId, int $dureeMinutes, ?int $roomId): array
    {
        $planning = Schedule::where('groupe_id', $groupeId)->where('actif', true)->first();

        if ($planning === null) {
            return [];
        }

        $libres = [];
        foreach (ScheduleSlot::JOURS as $jour) {
            $debut = Carbon::createFromFormat('H:i', self::HEURE_OUVERTURE);
            $limite = Carbon::createFromFormat('H:i', self::HEURE_FERMETURE);

            while ($debut->copy()->addMinutes($dureeMinutes)->lessThanOrEqualTo($limite)) {
                $fin = $debut->copy()->addMinutes($dureeMinutes);
                $erreurs = $this->conflits->verifier(
                    $planning->id, $jour,
                    $debut->format('H:i'), $fin->format('H:i'),
                    $teacherId, $roomId,
                    null,
                    $planning->groupe->etudiants()->count(),
                );

                if ($erreurs === []) {
                    $libres[] = [
                        'jour' => $jour,
                        'debut' => $debut->format('H:i'),
                        'fin' => $fin->format('H:i'),
                    ];
                }

                $debut->addMinutes(30);
            }
        }

        return $libres;
    }

    /**
     * Charge par enseignant : heures, cours, jours distincts (scopé).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function chargeEnseignants(?int $faculteId): Collection
    {
        $slots = $this->requeteCreneaux($faculteId, [])->get()
            ->filter(fn ($s) => $s->teacher_id !== null && $s->status !== 'cancelled');

        return $slots->groupBy('teacher_id')->map(function ($lignes, $teacherId) {
            $enseignant = $lignes->first()->teacher;
            $minutes = $lignes->sum(fn ($s) => $this->dureeMinutes($s->start_time, $s->end_time));

            return [
                'id' => $teacherId,
                'nom' => $enseignant->nomComplet(),
                'heures' => round($minutes / 60, 1),
                'cours' => $lignes->count(),
                'jours' => $lignes->pluck('day_of_week')->unique()->count(),
            ];
        })->sortByDesc('heures')->values();
    }

    /**
     * Conflits existants détectés par balayage : chevauchements salle /
     * enseignant / groupe entre créneaux actifs (hors même créneau).
     *
     * @return array<int, array<string, mixed>>
     */
    public function conflitsDetectes(?int $faculteId, ?string $type = null): array
    {
        $slots = $this->requeteCreneaux($faculteId, [])->get()
            ->filter(fn ($s) => $s->status !== 'cancelled')->values();

        $conflits = [];
        $vus = 0;

        foreach ($slots->groupBy('day_of_week') as $jour => $duJour) {
            $tries = $duJour->sortBy('start_time')->values();
            $n = $tries->count();

            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $tries[$i];
                    $b = $tries[$j];

                    if ($b->start_time >= $a->end_time) {
                        break;
                    }

                    if (! $a->chevauche($b->start_time, $b->end_time)) {
                        continue;
                    }

                    foreach ($this->classifier($a, $b) as $nature) {
                        if ($type !== null && $type !== 'tous' && $nature !== $type) {
                            continue;
                        }

                        $conflits[] = [
                            'id' => ++$vus,
                            'type' => $nature,
                            'jour' => $jour,
                            'debut' => max($a->start_time, $b->start_time),
                            'fin' => min($a->end_time, $b->end_time),
                            'a' => $this->resumer($a),
                            'b' => $this->resumer($b),
                        ];
                    }
                }
            }
        }

        return $conflits;
    }

    /**
     * Occupation des salles un jour donné : matrice salle × heure.
     *
     * @return array{salles: EloquentCollection, heures: array<int, string>, occupation: array<int, array<string, ?array>>}
     */
    public function occupationSalles(?int $faculteId, string $jour): array
    {
        $salles = Room::where('actif', true)->orderBy('code')->get();
        $slots = $this->requeteCreneaux($faculteId, ['jour' => $jour])->get()
            ->filter(fn ($s) => $s->room_id !== null);

        $heures = [];
        for ($h = 8; $h < 18; $h++) {
            $heures[] = sprintf('%02d:00', $h);
        }

        $occupation = [];
        foreach ($salles as $salle) {
            $occupation[$salle->id] = [];
            foreach ($heures as $heure) {
                $fin = sprintf('%02d:00', ((int) substr($heure, 0, 2)) + 1);
                $slot = $slots->where('room_id', $salle->id)
                    ->first(fn ($s) => $s->chevauche($heure, $fin));
                $occupation[$salle->id][$heure] = $slot !== null ? $this->resumer($slot) : null;
            }
        }

        return ['salles' => $salles, 'heures' => $heures, 'occupation' => $occupation];
    }

    /**
     * @param  array<string, mixed>  $filtres
     * @return Builder
     */
    private function requeteCreneaux(?int $faculteId, array $filtres, bool $inclureAnnules = false)
    {
        $query = ScheduleSlot::query()
            ->with(['matiere', 'teacher', 'room', 'schedule.groupe', 'schedule.anneeUniversitaire'])
            ->when(! $inclureAnnules, fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->whereHas('schedule', fn ($q) => $q->where('actif', true))
            ->whereHas('schedule.groupe', function ($q) use ($faculteId, $filtres) {
                if ($faculteId !== null) {
                    $q->where('faculte_id', $faculteId);
                }
                if (! empty($filtres['groupe_id'])) {
                    $q->where('groups.id', (int) $filtres['groupe_id']);
                }
                if (! empty($filtres['filiere'])) {
                    $q->where('filiere', $filtres['filiere']);
                }
                if (! empty($filtres['niveau'])) {
                    $q->where('niveau', $filtres['niveau']);
                }
            });

        foreach ([
            'teacher_id' => 'teacher_id', 'room_id' => 'room_id',
            'matiere_id' => 'matiere_id', 'day_of_week' => 'day_of_week',
        ] as $cle => $colonne) {
            if (! empty($filtres[$cle])) {
                $query->where($colonne, $filtres[$cle]);
            }
        }

        if (! empty($filtres['semestre'])) {
            $query->whereHas('schedule', fn ($q) => $q->where('semestre', (int) $filtres['semestre']));
        }

        if (! empty($filtres['annee_universitaire_id'])) {
            $query->whereHas('schedule', fn ($q) => $q->where('annee_universitaire_id', (int) $filtres['annee_universitaire_id']));
        }

        return $query->orderBy('day_of_week')->orderBy('start_time');
    }

    /**
     * @return array<int, string> natures : salle | enseignant | groupe
     */
    private function classifier(ScheduleSlot $a, ScheduleSlot $b): array
    {
        $natures = [];

        if ($a->room_id !== null && $a->room_id === $b->room_id) {
            $natures[] = 'salle';
        }

        if ($a->teacher_id !== null && $a->teacher_id === $b->teacher_id) {
            $natures[] = 'enseignant';
        }

        if ($a->schedule_id === $b->schedule_id) {
            $natures[] = 'groupe';
        }

        return $natures;
    }

    /**
     * @return array{id: int, matiere: string, groupe: string, enseignant: string, salle: string, debut: string, fin: string, planning_id: int}
     */
    private function resumer(ScheduleSlot $slot): array
    {
        return [
            'id' => $slot->id,
            'matiere' => $slot->matiere->nom,
            'groupe' => $slot->schedule->groupe->code,
            'enseignant' => $slot->teacher?->nomComplet() ?? '—',
            'salle' => $slot->room?->code ?? '—',
            'debut' => $slot->start_time,
            'fin' => $slot->end_time,
            'planning_id' => $slot->schedule_id,
        ];
    }

    private function dureeMinutes(string $debut, string $fin): int
    {
        return (int) Carbon::parse(substr($debut, 0, 5))
            ->diffInMinutes(Carbon::parse(substr($fin, 0, 5)));
    }
}
