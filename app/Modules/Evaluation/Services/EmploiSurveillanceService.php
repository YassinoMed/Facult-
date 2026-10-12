<?php

namespace App\Modules\Evaluation\Services;

use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Assemblage en lecture seule de l'emploi du temps des surveillances.
 *
 * Vue spécialisée des affectations EXISTANTES (`Surveillance` reliées aux
 * `Epreuve`) : AUCUNE écriture, AUCUNE décision d'affectation, AUCUN
 * second moteur. Les règles (besoins, poids de charge, cible 3 jours,
 * conflits jour-exact) sont celles de `SurveillanceEquilibrageService`,
 * `SurveillanceService` et `ConflitEpreuveService`, appliquées ici en
 * lecture pour l'affichage.
 *
 * Performance : requêtes groupées + eager loading uniquement — jamais de
 * requête par cellule du calendrier (test anti-N+1 dédié).
 */
class EmploiSurveillanceService
{
    public function __construct(
        private SurveillanceEquilibrageService $equilibrage,
        private ConflitEpreuveService $conflits,
    ) {}

    /**
     * Construit l'emploi complet d'un périmètre (filtres demandés).
     *
     * @param  array{session_id?: ?int, annee_universitaire_id?: ?int, semestre?: ?int, date_debut?: ?string, date_fin?: ?string, niveau?: ?string, groupe_id?: ?int, matiere_id?: ?int, teacher_id?: ?int, salle_id?: ?int, statut?: ?string}  $filtres
     * @return array{jours: Collection, enseignants: Collection, stats: array<string, mixed>, couverture: array<string, mixed>}
     */
    public function construireEmploi(?int $faculteId, array $filtres): array
    {
        $epreuves = $this->chargerEpreuves($faculteId, $filtres);

        $teacherIds = $epreuves->pluck('surveillants')->flatten()->pluck('teacher_id')
            ->filter()->unique()->values()->all();

        $dates = $epreuves->map(fn (Epreuve $e) => $e->date instanceof CarbonInterface ? $e->date->toDateString() : (string) $e->date)
            ->filter()->unique()->values()->all();

        $joursSemaine = $epreuves->map(fn (Epreuve $e) => strtolower($e->date instanceof CarbonInterface ? $e->date->format('l') : Carbon::parse($e->date)->format('l')))
            ->filter()->unique()->values()->all();

        // Préchargement groupé : créneaux pédagogiques par enseignant…
        $slotsParEnseignant = ($teacherIds !== [] && $joursSemaine !== [])
            ? ScheduleSlot::whereIn('teacher_id', $teacherIds)
                ->whereIn('day_of_week', $joursSemaine)
                ->where('status', '!=', 'cancelled')
                ->with('matiere')
                ->get()
                ->groupBy('teacher_id')
            : collect();

        // …et autres épreuves actives hors périmètre sur les mêmes dates
        // (conflits externes réels, même logique que la simulation).
        $idsChargees = $epreuves->pluck('id')->all();
        $autresEpreuves = $dates !== []
            ? Epreuve::whereIn(DB::raw('DATE(date)'), $dates)
                ->where('statut', '!=', 'cancelled')
                ->when($idsChargees !== [], fn ($q) => $q->whereNotIn('id', $idsChargees))
                ->with(['matiere', 'surveillants' => fn ($s) => $s->whereIn('statut', ['assigne', 'confirme'])->whereNotNull('teacher_id')])
                ->get()
            : collect();

        $jours = [];
        $charges = [];
        $joursPresence = [];
        $enrichies = [];
        foreach ($epreuves as $epreuve) {
            $enrichie = $this->enrichirEpreuve($epreuve, $slotsParEnseignant, $autresEpreuves, $epreuves);
            $enrichies[] = $enrichie;

            foreach ($enrichie['actifs'] as $surv) {
                if ($surv->teacher_id === null) {
                    continue;
                }
                $tId = (int) $surv->teacher_id;
                $charges[$tId] = ($charges[$tId] ?? 0.0)
                    + ($surv->role === 'reserve'
                        ? SurveillanceEquilibrageService::POIDS_GARDE_RESERVE
                        : SurveillanceEquilibrageService::POIDS_SURVEILLANCE_ACTIVE);
                $joursPresence[$tId][$enrichie['date_iso']] = true;
            }

            $jours[$enrichie['date_iso']][] = $enrichie;
        }
        ksort($jours);

        foreach ($jours as &$liste) {
            usort($liste, fn ($a, $b) => strcmp($a['heure_debut'], $b['heure_debut']) ?: $a['epreuve']->id <=> $b['epreuve']->id);
        }
        unset($liste);

        $enseignants = $this->syntheseEnseignants($epreuves, $charges, $joursPresence, $teacherIds);
        $stats = $this->statistiquesEquilibrage($enseignants);
        $couverture = $this->syntheseCouverture($enrichies);

        // Filtre statut (couverture/conflit) : calculé en mémoire comme sur
        // la page affectations — jamais en SQL par cellule.
        if (! empty($filtres['statut'])) {
            $jours = $this->filtrerParStatut($jours, (string) $filtres['statut']);
        }

        // Filtre enseignant : ne garde que les épreuves où il est mobilisé.
        if (! empty($filtres['teacher_id'])) {
            $jours = $this->filtrerParEnseignant($jours, (int) $filtres['teacher_id']);
        }

        return [
            'jours' => collect($jours),
            'enseignants' => $enseignants,
            'stats' => $stats,
            'couverture' => $couverture,
        ];
    }

    /**
     * @param  array{session_id?: ?int, annee_universitaire_id?: ?int, semestre?: ?int, date_debut?: ?string, date_fin?: ?string, niveau?: ?string, groupe_id?: ?int, matiere_id?: ?int, teacher_id?: ?int, salle_id?: ?int}  $filtres
     * @return \Illuminate\Database\Eloquent\Collection<int, Epreuve>
     */
    private function chargerEpreuves(?int $faculteId, array $filtres): \Illuminate\Database\Eloquent\Collection
    {
        return Epreuve::query()
            ->where('statut', '!=', 'cancelled')
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->when(! empty($filtres['session_id']), fn ($q) => $q->where('session_id', (int) $filtres['session_id']))
            ->when(! empty($filtres['annee_universitaire_id']), fn ($q) => $q->where('annee_universitaire_id', (int) $filtres['annee_universitaire_id']))
            // GO condition 3 : semestre/niveau dérivés de la chaîne LMD
            // pour les ECUE rattachées (legacy = standalone uniquement).
            ->when(! empty($filtres['semestre']), function ($q) use ($filtres) {
                $relatif = (int) $filtres['semestre'];

                $q->where(function ($w) use ($relatif) {
                    $w->whereHas('matiere', fn ($m) => $m->whereNull('ue_id')->where('semestre', $relatif))
                        ->orWhereHas('matiere.ue.semestre', fn ($s) => $s->whereRaw('((numero - 1) % 2) + 1 = ?', [$relatif]));
                });
            })
            ->when(! empty($filtres['date_debut']), fn ($q) => $q->whereDate('date', '>=', (string) $filtres['date_debut']))
            ->when(! empty($filtres['date_fin']), fn ($q) => $q->whereDate('date', '<=', (string) $filtres['date_fin']))
            ->when(! empty($filtres['niveau']), function ($q) use ($filtres) {
                $niveau = (string) $filtres['niveau'];

                $q->where(function ($w) use ($niveau) {
                    $w->whereHas('groupe', fn ($g) => $g->where('niveau', $niveau))
                        ->orWhereHas('matiere', fn ($m) => $m->whereNull('ue_id')->where('niveau', $niveau))
                        ->orWhereHas('matiere.ue.semestre.niveau', fn ($n) => $n->where('code', $niveau));
                });
            })
            ->when(! empty($filtres['groupe_id']), fn ($q) => $q->where('groupe_id', (int) $filtres['groupe_id']))
            ->when(! empty($filtres['matiere_id']), fn ($q) => $q->where('matiere_id', (int) $filtres['matiere_id']))
            ->when(! empty($filtres['salle_id']), fn ($q) => $q->where('salle_id', (int) $filtres['salle_id']))
            ->with([
                'matiere.ue.programme',
                'examen.session',
                'session',
                'salle',
                'groupe.etudiants',
                'candidats.salle',
                'surveillants.enseignant',
                'surveillants.salle',
            ])
            ->withCount(['candidats'])
            ->orderBy('date')
            ->orderBy('heure_debut')
            ->orderBy('id')
            ->get();
    }

    /**
     * Enrichit UNE épreuve en mémoire : besoins (moteur), surveillants
     * actifs, conflits (mêmes règles que l'affectation), statut visuel.
     *
     * @param  Collection<int, Collection<int, ScheduleSlot>>  $slotsParEnseignant
     * @param  Collection<int, Epreuve>  $autresEpreuves
     * @param  Collection<int, Epreuve>  $perimetre
     * @return array<string, mixed>
     */
    private function enrichirEpreuve(
        Epreuve $epreuve,
        Collection $slotsParEnseignant,
        Collection $autresEpreuves,
        Collection $perimetre
    ): array {
        // Besoins de couverture : le moteur est la source de vérité.
        $besoins = $this->equilibrage->evaluerBesoinsEpreuve($epreuve);

        $actifs = $epreuve->surveillants->whereIn('statut', ['assigne', 'confirme'])->values();
        $principaux = $actifs->where('role', 'principal');
        $adjoints = $actifs->whereIn('role', ['principal', 'adjoint', 'surveillant']);
        $gardes = $actifs->where('role', 'reserve');

        $conflits = $this->detecterConflits($epreuve, $actifs, $slotsParEnseignant, $autresEpreuves, $perimetre);

        $nbEtudiants = max(
            $epreuve->candidats_count ?? 0,
            (int) ($epreuve->capacite_necessaire ?? 0),
            $epreuve->groupe?->etudiants?->count() ?? 0
        );

        $couvertureComplete = $adjoints->count() >= $besoins['besoin_surveillants_actifs']
            && $gardes->count() >= $besoins['besoin_gardes']
            && $principaux->isNotEmpty();

        if ($conflits !== []) {
            $statut = 'conflit';
        } elseif ($couvertureComplete) {
            $statut = 'complet';
        } elseif ($actifs->isNotEmpty()) {
            $statut = 'partiel';
        } else {
            $statut = 'non_affecte';
        }

        $dateIso = $epreuve->date instanceof CarbonInterface
            ? $epreuve->date->toDateString()
            : (string) $epreuve->date;

        return [
            'epreuve' => $epreuve,
            'date_iso' => $dateIso,
            'heure_debut' => substr((string) $epreuve->heure_debut, 0, 5),
            'heure_fin' => substr((string) $epreuve->heure_fin, 0, 5),
            'besoins' => $besoins,
            'actifs' => $actifs,
            'principaux' => $principaux,
            'gardes' => $gardes,
            'conflits' => $conflits,
            'statut' => $statut,
            'couverture_complete' => $couvertureComplete,
            'nb_etudiants' => $nbEtudiants,
        ];
    }

    /**
     * Conflits d'une épreuve calculés en mémoire sur données préchargées :
     * capacité, enseignant inactif, double affectation même créneau
     * (périmètre + hors périmètre), créneau pédagogique du JOUR exact.
     * Mêmes règles que `SurveillanceService` et le moteur d'équilibrage.
     *
     * @param  Collection<int, Surveillance>  $actifs
     * @param  Collection<int, Collection<int, ScheduleSlot>>  $slotsParEnseignant
     * @param  Collection<int, Epreuve>  $autresEpreuves
     * @param  Collection<int, Epreuve>  $perimetre
     * @return array<int, array{type: string, message: string}>
     */
    private function detecterConflits(
        Epreuve $epreuve,
        Collection $actifs,
        Collection $slotsParEnseignant,
        Collection $autresEpreuves,
        Collection $perimetre
    ): array {
        $conflits = [];
        $dateIso = $epreuve->date instanceof CarbonInterface
            ? $epreuve->date->toDateString()
            : (string) $epreuve->date;
        $jourSemaine = strtolower($epreuve->date instanceof CarbonInterface
            ? $epreuve->date->format('l')
            : Carbon::parse($epreuve->date)->format('l'));
        $debut = substr((string) $epreuve->heure_debut, 0, 5);
        $fin = substr((string) $epreuve->heure_fin, 0, 5);

        // Capacité salle vs effectif réel.
        if ($epreuve->salle !== null && $epreuve->salle->capacite !== null) {
            $nb = max(
                $epreuve->candidats_count ?? 0,
                (int) ($epreuve->capacite_necessaire ?? 0),
                $epreuve->groupe?->etudiants?->count() ?? 0
            );
            if ($nb > (int) $epreuve->salle->capacite) {
                $conflits[] = [
                    'type' => 'capacite',
                    'message' => "Capacité insuffisante : {$nb} étudiants pour {$epreuve->salle->capacite} places ({$epreuve->salle->code}).",
                ];
            }
        }

        foreach ($actifs as $surv) {
            if ($surv->teacher_id === null) {
                continue;
            }
            $nom = $surv->enseignant?->nomComplet() ?? 'Enseignant';

            if ($surv->enseignant !== null && ! $surv->enseignant->actif) {
                $conflits[] = ['type' => 'enseignant_inactif', 'message' => "{$nom} est inactif."];
            }

            // Double affectation même créneau : périmètre affiché…
            $double = $perimetre->where('id', '!=', $epreuve->id)->first(
                fn (Epreuve $autre) => ($autre->date instanceof CarbonInterface ? $autre->date->toDateString() : (string) $autre->date) === $dateIso
                    && (string) $epreuve->heure_debut < (string) $autre->heure_fin
                    && (string) $autre->heure_debut < (string) $epreuve->heure_fin
                    && $autre->surveillants->contains(
                        fn ($s) => (int) $s->teacher_id === (int) $surv->teacher_id && in_array($s->statut, ['assigne', 'confirme'], true)
                    )
            );
            // …ou hors périmètre (autre session filtrée).
            $double ??= $autresEpreuves->first(
                fn (Epreuve $autre) => $autre->date->format('Y-m-d') === $dateIso
                    && $debut < substr((string) $autre->heure_fin, 0, 5)
                    && substr((string) $autre->heure_debut, 0, 5) < $fin
                    && $autre->surveillants->contains('teacher_id', (int) $surv->teacher_id)
            );
            if ($double !== null) {
                $conflits[] = ['type' => 'double_affectation', 'message' => "{$nom} est déjà affecté sur « {$double->code} » à ce créneau."];
            }

            // Créneau pédagogique du JOUR exact (créneaux hebdomadaires).
            $slot = $slotsParEnseignant->get((int) $surv->teacher_id, collect())
                ->first(fn (ScheduleSlot $s) => $s->day_of_week === $jourSemaine && $s->chevauche($debut, $fin));
            if ($slot !== null) {
                $conflits[] = ['type' => 'cours_regulier', 'message' => "{$nom} a déjà un cours (« {$slot->matiere?->nom} ») à ce créneau."];
            }
        }

        return $conflits;
    }

    /**
     * Synthèse par enseignant mobilisé : gardes, jours, charge pondérée
     * (mêmes poids que le moteur), dispersion au-delà de 3 jours
     * (préférence du moteur, jamais contrainte dure).
     *
     * @param  array<int, float>  $charges
     * @param  array<int, array<string, bool>>  $joursPresence
     * @return Collection<int, array<string, mixed>>
     */
    private function syntheseEnseignants(Collection $epreuves, array $charges, array $joursPresence, array $teacherIds): Collection
    {
        if ($teacherIds === []) {
            return collect();
        }

        $noms = Teacher::whereIn('id', $teacherIds)->get()->keyBy('id');

        $compteurs = [];
        foreach ($epreuves as $epreuve) {
            foreach ($epreuve->surveillants->whereIn('statut', ['assigne', 'confirme']) as $surv) {
                if ($surv->teacher_id === null) {
                    continue;
                }
                $tId = (int) $surv->teacher_id;
                $compteurs[$tId]['gardes'] = ($compteurs[$tId]['gardes'] ?? 0) + 1;
                if ($surv->role === 'reserve') {
                    $compteurs[$tId]['reserves'] = ($compteurs[$tId]['reserves'] ?? 0) + 1;
                }
            }
        }

        return collect($teacherIds)
            ->map(function (int $tId) use ($noms, $compteurs, $charges, $joursPresence) {
                $nbJours = count($joursPresence[$tId] ?? []);

                return [
                    'teacher_id' => $tId,
                    'nom' => $noms->get($tId)?->nomComplet() ?? 'Enseignant',
                    'actif' => (bool) ($noms->get($tId)?->actif ?? true),
                    'gardes' => $compteurs[$tId]['gardes'] ?? 0,
                    'reserves' => $compteurs[$tId]['reserves'] ?? 0,
                    'jours' => $nbJours,
                    'charge' => round($charges[$tId] ?? 0.0, 1),
                    'dispersion' => $nbJours > SurveillanceEquilibrageService::CIBLE_JOURS_PRESENCE,
                ];
            })
            ->sortBy([['charge', 'desc'], ['nom', 'asc']])
            ->values();
    }

    /**
     * Panneau ÉQUILIBRAGE calculé sur les affectations réelles du périmètre.
     *
     * @param  Collection<int, array<string, mixed>>  $enseignants
     * @return array<string, mixed>
     */
    private function statistiquesEquilibrage(Collection $enseignants): array
    {
        $charges = $enseignants->pluck('charge')->all();
        $jours = $enseignants->pluck('jours')->all();

        return [
            'enseignants_mobilises' => $enseignants->count(),
            'surveillances' => $enseignants->sum('gardes'),
            'gardes_reserve' => $enseignants->sum('reserves'),
            'charge_min' => $charges !== [] ? min($charges) : 0,
            'charge_max' => $charges !== [] ? max($charges) : 0,
            'charge_moyenne' => $charges !== [] ? round(array_sum($charges) / count($charges), 2) : 0,
            'ecart' => $charges !== [] ? round(max($charges) - min($charges), 1) : 0,
            'jours_moyens' => $jours !== [] ? round(array_sum($jours) / count($jours), 1) : 0,
            'enseignants_disperses' => $enseignants->where('dispersion', true)->count(),
        ];
    }

    /**
     * Synthèse de couverture sur le périmètre NON filtré par statut (les
     * statuts visuels viennent d'enrichirEpreuve : zéro requête).
     *
     * @param  array<int, array<string, mixed>>  $enrichies
     * @return array<string, mixed>
     */
    private function syntheseCouverture(array $enrichies): array
    {
        $complets = 0;
        $partiels = 0;
        $nonAffectes = 0;
        $conflits = 0;
        $totalGardes = 0;
        $totalReserves = 0;

        foreach ($enrichies as $e) {
            match ($e['statut']) {
                'complet' => $complets++,
                'partiel' => $partiels++,
                default => $nonAffectes++,
            };
            // 'conflit' garde son statut de fond : la couverture compte
            // l'affectation réelle, pas l'alerte.
            if ($e['statut'] === 'conflit') {
                $conflits++;
                $complets += (int) ($e['couverture_complete'] ?? false);
                $partiels += (int) ! ($e['couverture_complete'] ?? false);
            }

            $totalGardes += $e['actifs']->count();
            $totalReserves += $e['gardes']->count();
        }

        $total = count($enrichies);

        return [
            'total_epreuves' => $total,
            'completes' => $complets,
            'partielles' => $partiels,
            'non_affectees' => $nonAffectes,
            'conflits' => $conflits,
            'taux' => $total > 0 ? round($complets / $total * 100, 1) : 0,
            'total_gardes' => $totalGardes,
            'total_reserves' => $totalReserves,
        ];
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $jours
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function filtrerParStatut(array $jours, string $statut): array
    {
        foreach ($jours as $date => $liste) {
            $jours[$date] = array_values(array_filter(
                $liste,
                fn (array $e) => $statut === 'conflit'
                    ? $e['conflits'] !== []
                    : $e['statut'] === $statut
            ));
            if ($jours[$date] === []) {
                unset($jours[$date]);
            }
        }

        return $jours;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $jours
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function filtrerParEnseignant(array $jours, int $teacherId): array
    {
        foreach ($jours as $date => $liste) {
            $jours[$date] = array_values(array_filter(
                $liste,
                fn (array $e) => $e['actifs']->contains(fn ($s) => (int) $s->teacher_id === $teacherId)
            ));
            if ($jours[$date] === []) {
                unset($jours[$date]);
            }
        }

        return $jours;
    }

    /**
     * Session représentative du périmètre pour l'en-tête (la plus fréquente).
     */
    public function sessionDominante(Collection $epreuves): ?SessionExamen
    {
        $parSession = $epreuves->groupBy('session_id')->sortByDesc(fn ($g) => $g->count())->first();

        return $parSession?->first()?->session;
    }
}
