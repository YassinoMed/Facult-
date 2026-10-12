<?php

namespace App\Modules\Schedule\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Schedule\Models\ScheduleChange;
use App\Modules\Schedule\Models\ScheduleException;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\StudentDossiers\Contracts\PdfGeneratorInterface;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Emploi du temps enseignant : créneaux où l'enseignant connecté est
 * affecté (jamais d'ID enseignant client), tous plannings confondus.
 * Lecture seule — toute anomalie se signale à la scolarité.
 *
 * Vues `?vue=` : `semaine` (défaut, grille principale), `jour`,
 * `liste` (chronologique bornée). Filtres `semestre/groupe/matiere`
 * restreints aux valeurs des PROPRES créneaux (un filtre inconnu rend
 * vide, jamais d'élargissement). Paramètre `teacher_id` éventuel
 * systématiquement ignoré.
 */
class MonEmploiEnseignantController extends Controller
{
    public const VUES = ['semaine', 'jour', 'liste'];

    public function semaine(Request $request): View
    {
        $enseignant = $this->enseignantConnecte($request);

        $vue = in_array($request->input('vue'), self::VUES, true) ? $request->input('vue') : 'semaine';
        $decalage = max(-52, min(52, (int) $request->input('semaine', 0)));
        $lundi = Carbon::now()->startOfWeek(Carbon::MONDAY)->addWeeks($decalage);

        $filtres = $this->filtresValides($request, $enseignant);
        $slots = $this->mesSlots($enseignant, $filtres);

        // Période couverte (exceptions préchargées en 1 requête, jamais N+1).
        [$debutPeriode, $finPeriode] = $this->periodeVue($request, $vue, $lundi);
        $exceptions = $this->exceptionsPeriode($slots, $debutPeriode, $finPeriode);

        $grille = $this->construireGrille($slots, $lundi, $decalage, $vue, $request, $exceptions);

        return view('modules.schedule.mon-planning-enseignant', [
            'enseignant' => $enseignant,
            'vue' => $vue,
            'jours' => $grille['jours'],
            'liste' => $grille['liste'],
            'lundi' => $lundi,
            'decalage' => $decalage,
            'debutPeriode' => $debutPeriode,
            'finPeriode' => $finPeriode,
            'aujourdhui' => Carbon::today()->toDateString(),
            'filtres' => $filtres,
            'choix' => $this->choixFiltres($enseignant),
            'widgets' => $this->widgets($slots, $exceptions, $debutPeriode, $finPeriode),
            'chevauchements' => $this->chevauchements($slots),
            'changements' => $this->changementsRecents($slots),
        ]);
    }

    /**
     * Détail d'un créneau : ownership stricte (404 sinon), durée calculée,
     * historique des changements, aucune mutation ici.
     */
    public function show(Request $request, ScheduleSlot $slot): View
    {
        $enseignant = $this->enseignantConnecte($request);
        abort_if((int) $slot->teacher_id !== (int) $enseignant->id, 404);

        $slot->loadMissing(['matiere', 'room', 'schedule.groupe', 'schedule.anneeUniversitaire']);

        $exceptions = ScheduleException::where('schedule_slot_id', $slot->id)
            ->with(['nouvelleSalle', 'nouvelEnseignant'])
            ->orderByDesc('exception_date')
            ->limit(10)
            ->get();

        $historique = ScheduleChange::where('schedule_slot_id', $slot->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return view('modules.schedule.creneau-enseignant', [
            'enseignant' => $enseignant,
            'slot' => $slot,
            'exceptions' => $exceptions,
            'historique' => $historique,
            'dureeMinutes' => $this->dureeMinutes($slot->start_time, $slot->end_time),
        ]);
    }

    /**
     * Export PDF de la semaine affichée : même périmètre que la
     * consultation (mes créneaux + mes filtres), jamais d'élargissement.
     */
    public function exporterPdf(Request $request, PdfGeneratorInterface $pdf): Response
    {
        $enseignant = $this->enseignantConnecte($request);

        $decalage = max(-52, min(52, (int) $request->input('semaine', 0)));
        $lundi = Carbon::now()->startOfWeek(Carbon::MONDAY)->addWeeks($decalage);

        $filtres = $this->filtresValides($request, $enseignant);
        $slots = $this->mesSlots($enseignant, $filtres);
        $exceptions = $this->exceptionsPeriode($slots, $lundi->toDateString(), $lundi->copy()->addDays(6)->toDateString());

        $widgets = $this->widgets($slots, $exceptions, $lundi->toDateString(), $lundi->copy()->addDays(6)->toDateString());

        $html = view('modules.schedule.mon-planning-enseignant-pdf', [
            'enseignant' => $enseignant,
            'jours' => $this->construireSemaine($slots, $lundi, $exceptions),
            'lundi' => $lundi,
            'filtres' => $filtres,
            'widgets' => $widgets,
        ])->render();

        $contenu = $pdf->generer($html, ['paper' => 'A4', 'orientation' => 'landscape']);

        return response($contenu, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="mon-emploi-du-temps-'.$lundi->format('Y-m-d').'.pdf"',
        ]);
    }

    private function enseignantConnecte(Request $request): Teacher
    {
        return Teacher::findOrFail($request->session()->get('enseignant_auth_id'));
    }

    /**
     * Filtres validés côté serveur, restreints à mes propres valeurs :
     * semestre entier, groupe/matière parmi MES ids (sinon vide).
     *
     * @return array{semestre: ?int, groupe: ?int, matiere: ?int}
     */
    private function filtresValides(Request $request, Teacher $enseignant): array
    {
        $mesGroupes = $enseignant->slots()->join('schedules', 'schedule_slots.schedule_id', '=', 'schedules.id')
            ->distinct()->pluck('schedules.groupe_id')->filter()->map(fn ($id) => (int) $id)->all();
        $mesMatieres = $enseignant->slots()->distinct()->pluck('matiere_id')->filter()->map(fn ($id) => (int) $id)->all();

        $semestre = $request->filled('semestre') ? (int) $request->input('semestre') : null;
        $groupe = $request->filled('groupe') ? (int) $request->input('groupe') : null;
        $matiere = $request->filled('matiere') ? (int) $request->input('matiere') : null;

        return [
            'semestre' => ($semestre !== null && $semestre >= 1 && $semestre <= 12) ? $semestre : null,
            'groupe' => ($groupe !== null && in_array($groupe, $mesGroupes, true)) ? $groupe : ($request->filled('groupe') ? -1 : null),
            'matiere' => ($matiere !== null && in_array($matiere, $mesMatieres, true)) ? $matiere : ($request->filled('matiere') ? -1 : null),
        ];
    }

    /**
     * Choix proposés dans les selects : UNIQUEMENT mes valeurs (jamais
     * d'énumération d'autrui).
     *
     * @return array{groupes: Collection<int, string>, matieres: Collection<int, string>, semestres: array<int>}
     */
    private function choixFiltres(Teacher $enseignant): array
    {
        $slots = $enseignant->slots()->with(['matiere', 'schedule.groupe'])->get();

        return [
            'groupes' => $slots->mapWithKeys(fn ($s) => $s->schedule?->groupe_id ? [$s->schedule->groupe_id => $s->schedule->groupe?->nom ?? ('#'.$s->schedule->groupe_id)] : [])->sort(),
            'matieres' => $slots->mapWithKeys(fn ($s) => $s->matiere_id ? [$s->matiere_id => $s->matiere?->nom ?? ('#'.$s->matiere_id)] : [])->sort(),
            'semestres' => $slots->map(fn ($s) => $s->schedule?->semestre)->filter()->unique()->sort()->values()->all(),
        ];
    }

    /**
     * @param  array{semestre: ?int, groupe: ?int, matiere: ?int}  $filtres
     * @return Collection<int, ScheduleSlot>
     */
    private function mesSlots(Teacher $enseignant, array $filtres)
    {
        // Sentinelle -1 = filtre inconnu → vide garanti (jamais d'élargissement).
        if (in_array(-1, $filtres, true)) {
            return collect();
        }

        return $enseignant->slots()
            ->where('schedule_slots.status', '!=', 'cancelled')
            ->when($filtres['matiere'] !== null, fn ($q) => $q->where('schedule_slots.matiere_id', $filtres['matiere']))
            ->when($filtres['groupe'] !== null || $filtres['semestre'] !== null, fn ($q) => $q->whereHas('schedule', function ($s) use ($filtres) {
                if ($filtres['groupe'] !== null) {
                    $s->where('groupe_id', $filtres['groupe']);
                }
                if ($filtres['semestre'] !== null) {
                    $s->where('semestre', $filtres['semestre']);
                }
            }))
            ->with(['matiere', 'room', 'schedule.groupe'])
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function periodeVue(Request $request, string $vue, Carbon $lundi): array
    {
        if ($vue === 'liste') {
            $debut = $this->dateOuDefaut($request->input('debut'), $lundi->toDateString());
            $fin = $this->dateOuDefaut($request->input('fin'), $lundi->copy()->addDays(28)->toDateString());
            // Fenêtre plafonnée à 90 jours (pas de déversement d'historique).
            if (Carbon::parse($debut)->diffInDays(Carbon::parse($fin), false) > 90) {
                $fin = Carbon::parse($debut)->addDays(90)->toDateString();
            }
            if ($fin < $debut) {
                [$debut, $fin] = [$fin, $debut];
            }

            return [$debut, $fin];
        }

        return [$lundi->toDateString(), $lundi->copy()->addDays(6)->toDateString()];
    }

    /**
     * Exceptions de MES créneaux sur la période : 1 requête, indexées
     * [slot_id][date] (jamais de requête par créneau-jour).
     *
     * @param  Collection<int, ScheduleSlot>  $slots
     * @return array<int, array<string, ScheduleException>>
     */
    private function exceptionsPeriode($slots, string $debut, string $fin): array
    {
        $ids = $slots->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $carte = [];

        ScheduleException::whereIn('schedule_slot_id', $ids)
            // `whereDate` (pattern du projet — cf. ScheduleController:101,
            // PresenceEmploiDuTempsService:74) : le cast `date` du modèle
            // persiste 'Y-m-d H:i:s' et un `whereBetween` borné au jour
            // exclurait silencieusement les exceptions des jours de bornes
            // (lundi/dimanche) sous SQLite (comparaison de chaînes).
            ->whereDate('exception_date', '>=', $debut)
            ->whereDate('exception_date', '<=', $fin)
            ->with(['nouvelleSalle', 'nouvelEnseignant'])
            ->orderBy('exception_date')
            ->chunk(500, function ($lot) use (&$carte) {
                foreach ($lot as $exception) {
                    $carte[$exception->schedule_slot_id][substr((string) $exception->exception_date, 0, 10)] = $exception;
                }
            });

        return $carte;
    }

    /**
     * @param  Collection<int, ScheduleSlot>  $slots
     * @param  array<int, array<string, ScheduleException>>  $exceptions
     * @return array{jours: array<string, array<int, array<string, mixed>>>, liste: array<int, array<string, mixed>>}
     */
    private function construireGrille($slots, Carbon $lundi, int $decalage, string $vue, Request $request, array $exceptions): array
    {
        if ($vue === 'jour') {
            $jourDemande = in_array($request->input('jour'), ScheduleSlot::JOURS, true)
                ? $request->input('jour')
                : strtolower(Carbon::now()->format('l'));
            $index = array_search($jourDemande, ScheduleSlot::JOURS, true);
            $date = $lundi->copy()->addDays($index)->toDateString();

            return [
                'jours' => [$jourDemande => $this->creneauxDuJour($slots, $jourDemande, $date, $exceptions)],
                'liste' => [],
            ];
        }

        if ($vue === 'liste') {
            [$debut, $fin] = $this->periodeVue($request, 'liste', $lundi);
            $lignes = [];

            $curseur = Carbon::parse($debut);
            while ($curseur->toDateString() <= $fin) {
                $jour = strtolower($curseur->format('l'));
                foreach ($this->creneauxDuJour($slots, $jour, $curseur->toDateString(), $exceptions) as $ligne) {
                    $lignes[] = $ligne;
                }
                $curseur->addDay();
            }

            return ['jours' => [], 'liste' => $lignes];
        }

        $jours = [];
        foreach (ScheduleSlot::JOURS as $index => $jour) {
            $jours[$jour] = $this->creneauxDuJour($slots, $jour, $lundi->copy()->addDays($index)->toDateString(), $exceptions);
        }

        return ['jours' => $jours, 'liste' => []];
    }

    /**
     * @param  Collection<int, ScheduleSlot>  $slots
     * @param  array<int, array<string, ScheduleException>>  $exceptions
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function construireSemaine($slots, Carbon $lundi, array $exceptions): array
    {
        $jours = [];
        foreach (ScheduleSlot::JOURS as $index => $jour) {
            $jours[$jour] = $this->creneauxDuJour($slots, $jour, $lundi->copy()->addDays($index)->toDateString(), $exceptions);
        }

        return $jours;
    }

    /**
     * @param  Collection<int, ScheduleSlot>  $slots
     * @param  array<int, array<string, ScheduleException>>  $exceptions
     * @return array<int, array<string, mixed>>
     */
    private function creneauxDuJour($slots, string $jour, string $date, array $exceptions): array
    {
        $lignes = [];

        foreach ($slots->where('day_of_week', $jour)->sortBy('start_time') as $slot) {
            /** @var ScheduleSlot $slot */
            $exception = $exceptions[$slot->id][$date] ?? null;
            $ligne = $this->serialiser($slot, $date, $exception);

            if ($ligne !== null) {
                $lignes[] = $ligne;
            }
        }

        return $lignes;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serialiser(ScheduleSlot $slot, string $date, $exception): ?array
    {
        // Exception ponctuelle prioritaire sur le créneau récurrent.
        // Une séance annulée par exception RESTE affichée (badge + motif),
        // jamais masquée silencieusement.
        $debut = $exception?->new_start_time ?? $slot->start_time;
        $fin = $exception?->new_end_time ?? $slot->end_time;

        if ($debut === null || $fin === null) {
            return null;
        }

        $debutPropre = substr((string) $debut, 0, 5);
        $finPropre = substr((string) $fin, 0, 5);
        $dureeMin = $this->dureeMinutes($debutPropre, $finPropre);
        $heures = floor($dureeMin / 60);
        $minutes = $dureeMin % 60;
        $dureeTexte = $minutes > 0 ? "{$heures}h {$minutes}min" : "{$heures} h";

        return [
            'id' => $slot->id,
            'date' => $date,
            'debut' => $debutPropre,
            'fin' => $finPropre,
            'duree_minutes' => $dureeMin,
            'duree_texte' => $dureeTexte,
            'matiere' => $slot->matiere?->nom ?? '—',
            'matiere_code' => $slot->matiere?->code,
            'matiere_coeff' => $slot->matiere?->coefficient,
            'matiere_credits' => $slot->matiere?->credits,
            'groupe' => $slot->schedule?->groupe?->nom,
            'salle' => $exception?->nouvelleSalle?->code ?? $slot->room?->code,
            'salle_capacite' => $exception?->nouvelleSalle?->capacite ?? $slot->room?->capacite,
            'type' => $slot->type,
            'statut' => $exception?->status ?? $slot->status,
            'exception' => $exception !== null,
            'motif' => $exception?->reason,
            'deplace' => $exception !== null && $exception->status === 'moved',
            'enseignant_remplace' => $exception !== null && $exception->new_teacher_id !== null,
            'salle_normale' => $slot->room?->code,
            'debut_normal' => substr((string) $slot->start_time, 0, 5),
            'fin_normal' => substr((string) $slot->end_time, 0, 5),
        ];
    }

    /**
     * Widgets du tableau de bord EDT : tout est calculé sur les lignes
     * affichées (jamais sur des données absentes).
     *
     * @param  Collection<int, ScheduleSlot>  $slots
     * @param  array<int, array<string, ScheduleException>>  $exceptions
     * @return array<string, mixed>
     */
    private function widgets($slots, array $exceptions, string $debutPeriode, string $finPeriode): array
    {
        $aujourdhui = Carbon::today();
        $maintenant = Carbon::now()->format('H:i');

        $lignesPeriode = [];
        $curseur = Carbon::parse($debutPeriode);
        while ($curseur->toDateString() <= $finPeriode) {
            foreach ($this->creneauxDuJour($slots, strtolower($curseur->format('l')), $curseur->toDateString(), $exceptions) as $ligne) {
                $lignesPeriode[] = $ligne;
            }
            $curseur->addDay();
        }

        $effectives = array_values(array_filter($lignesPeriode, fn ($l) => $l['statut'] !== 'cancelled'));
        $aujourdhuiLignes = array_values(array_filter($effectives, fn ($l) => $l['date'] === $aujourdhui->toDateString()));

        $prochaine = null;
        foreach ($effectives as $ligne) {
            if ($ligne['date'] > $aujourdhui->toDateString()
                || ($ligne['date'] === $aujourdhui->toDateString() && $ligne['fin'] > $maintenant)) {
                $prochaine = $ligne;
                break;
            }
        }

        $minutes = 0;
        foreach ($effectives as $ligne) {
            $minutes += $this->dureeMinutes($ligne['debut'], $ligne['fin']);
        }

        $annulees = count(array_filter($lignesPeriode, fn ($l) => $l['statut'] === 'cancelled'));
        $deplacees = count(array_filter($lignesPeriode, fn ($l) => ! empty($l['deplace'])));
        $incompletes = count(array_filter($lignesPeriode, fn ($l) => empty($l['salle']) || empty($l['groupe'])));

        return [
            'jourActuel' => $aujourdhui->translatedFormat('l d F Y'),
            'nbSeancesJour' => count($aujourdhuiLignes),
            'nbSeancesPeriode' => count($effectives),
            'prochaine' => $prochaine,
            'heuresPlanifiees' => round($minutes / 60, 1),
            'nbAnnulees' => $annulees,
            'nbDeplacees' => $deplacees,
            'nbIncompletes' => $incompletes,
        ];
    }

    /**
     * Chevauchements de MES créneaux (même règle stricte que le moteur :
     * fin == début = OK), calculés en mémoire sur les lignes chargées.
     *
     * @param  Collection<int, ScheduleSlot>  $slots
     * @return array<int, array{jour: string, a: string, b: string, debut: string, fin: string}>
     */
    private function chevauchements($slots): array
    {
        $trouves = [];

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

                    if ($a->chevauche($b->start_time, $b->end_time)) {
                        $trouves[] = [
                            'jour' => $jour,
                            'a' => ($a->matiere?->nom ?? '—').' ('.substr((string) $a->start_time, 0, 5).'–'.substr((string) $a->end_time, 0, 5).')',
                            'b' => ($b->matiere?->nom ?? '—').' ('.substr((string) $b->start_time, 0, 5).'–'.substr((string) $b->end_time, 0, 5).')',
                            'debut' => max($a->start_time, $b->start_time),
                            'fin' => min($a->end_time, $b->end_time),
                        ];
                    }
                }
            }
        }

        return $trouves;
    }

    /**
     * Changements récents sur MES créneaux (historique immuable).
     *
     * @param  Collection<int, ScheduleSlot>  $slots
     * @return Collection<int, ScheduleChange>
     */
    private function changementsRecents($slots)
    {
        $ids = $slots->pluck('id')->all();

        if ($ids === []) {
            return collect();
        }

        return ScheduleChange::whereIn('schedule_slot_id', $ids)
            ->orderByDesc('id')
            ->limit(5)
            ->get();
    }

    /**
     * Date `Y-m-d` ou défaut (jamais de 500 sur saisie invalide).
     */
    private function dateOuDefaut(mixed $valeur, string $defaut): string
    {
        if (is_string($valeur) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur) === 1) {
            try {
                return Carbon::createFromFormat('Y-m-d', $valeur)->toDateString();
            } catch (\Throwable) {
                // Repli : valeur par défaut ci-dessous.
            }
        }

        return $defaut;
    }

    private function dureeMinutes(?string $debut, ?string $fin): int
    {
        if ($debut === null || $fin === null || strlen($debut) < 5 || strlen($fin) < 5) {
            return 0;
        }

        $minutes = ((int) substr($fin, 0, 2) * 60 + (int) substr($fin, 3, 2))
            - ((int) substr($debut, 0, 2) * 60 + (int) substr($debut, 3, 2));

        return max(0, $minutes);
    }
}
