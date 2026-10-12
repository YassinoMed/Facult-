<?php

namespace App\Modules\Schedule\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleException;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Services\ScheduleNotificationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Emploi du temps étudiant : planning de SON groupe uniquement
 * (jamais d'ID groupe client). Semaine navigable + endpoint JSON de
 * réactivité (version cache + slots si changement).
 */
class MonEmploiDuTempsController extends Controller
{
    public function __construct(
        private readonly ScheduleNotificationService $notifications,
    ) {}

    public function semaine(Request $request): View
    {
        $etudiant = $this->etudiantConnecte($request);
        $planning = $this->planningDuGroupe($etudiant);
        abort_if($planning === null, 404, 'Aucun emploi du temps pour votre groupe.');

        $decalage = (int) $request->input('semaine', 0);
        $decalage = max(-52, min(52, $decalage));

        $lundi = Carbon::now()->startOfWeek(Carbon::MONDAY)->addWeeks($decalage);

        return view('modules.schedule.mon-planning', [
            'etudiant' => $etudiant,
            'planning' => $planning,
            'jours' => $this->construireSemaine($planning, $lundi),
            'lundi' => $lundi,
            'decalage' => $decalage,
            'version' => $this->notifications->version($planning),
            'delaiSondage' => (int) config('schedule.poll_interval', 45),
            'aujourdhui' => Carbon::today()->toDateString(),
        ]);
    }

    /**
     * Sondage réactif (§13/§18) : version cachée comparée, slots
     * renvoyés uniquement en cas de changement. Prêt pour broadcast.
     */
    public function actualisations(Request $request): JsonResponse
    {
        $etudiant = $this->etudiantConnecte($request);
        $planning = $this->planningDuGroupe($etudiant);
        abort_if($planning === null, 404);

        $version = $this->notifications->version($planning);
        $depuis = (string) $request->input('depuis', '');

        if ($depuis !== '' && $depuis === $version) {
            return response()->json(['version' => $version, 'updated' => false, 'slots' => []]);
        }

        $lundi = Carbon::now()->startOfWeek(Carbon::MONDAY)
            ->addWeeks(max(-52, min(52, (int) $request->input('semaine', 0))));

        return response()->json([
            'version' => $version,
            'updated' => true,
            'slots' => $this->serialiserSemaine($planning, $lundi),
        ]);
    }

    private function etudiantConnecte(Request $request): Etudiant
    {
        return Etudiant::with('faculte')->findOrFail($request->session()->get('etudiant_auth_id'));
    }

    private function planningDuGroupe(Etudiant $etudiant): ?Schedule
    {
        if ($etudiant->groupe_id === null) {
            return null;
        }

        // SCHEDULE-09 (clôture) : seuls les plannings PUBLIÉS (ou
        // verrouillés — `verrouille` n'est accessible que depuis `publie`,
        // cf. `Schedule::TRANSITIONS`) sont exposés aux étudiants. Les
        // brouillons/relecture/valide restent côté administration — sans
        // cela, le contrôle pré-publication (§12.19 : conflits bloquants)
        // serait contourné par le portail.
        return Schedule::where('groupe_id', $etudiant->groupe_id)
            ->where('actif', true)
            ->whereIn('statut', ['publie', 'verrouille'])
            ->with(['groupe', 'slots.matiere', 'slots.teacher', 'slots.room'])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function construireSemaine(Schedule $planning, Carbon $lundi): array
    {
        $jours = [];
        foreach (ScheduleSlot::JOURS as $index => $jour) {
            $jours[$jour] = $this->creneauxDuJour($planning, $jour, $lundi->copy()->addDays($index));
        }

        return $jours;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function creneauxDuJour(Schedule $planning, string $jour, Carbon $date): array
    {
        $lignes = [];

        foreach ($planning->slots->where('day_of_week', $jour)->sortBy('start_time') as $slot) {
            /** @var ScheduleSlot $slot */
            $exception = $slot->exceptions()
                ->whereDate('exception_date', $date->toDateString())
                ->with(['nouvelleSalle', 'nouvelEnseignant'])
                ->first();

            $lignes[] = $this->serialiser($slot, $date, $exception);
        }

        return $lignes;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serialiserSemaine(Schedule $planning, Carbon $lundi): array
    {
        $lignes = [];
        foreach (ScheduleSlot::JOURS as $index => $jour) {
            foreach ($this->creneauxDuJour($planning, $jour, $lundi->copy()->addDays($index)) as $ligne) {
                $lignes[] = array_merge($ligne, ['jour' => $jour]);
            }
        }

        return $lignes;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialiser(ScheduleSlot $slot, Carbon $date, ?ScheduleException $exception): array
    {
        // Exception ponctuelle prioritaire sur le créneau récurrent.
        $debut = $exception?->new_start_time ?? $slot->start_time;
        $fin = $exception?->new_end_time ?? $slot->end_time;
        $salle = $exception?->nouvelleSalle?->code ?? $slot->room?->code;
        $enseignant = $exception?->nouvelEnseignant?->nomComplet() ?? $slot->teacher?->nomComplet();
        $statut = $exception?->status ?? $slot->status;

        return [
            'id' => $slot->id,
            'date' => $date->toDateString(),
            'debut' => substr((string) $debut, 0, 5),
            'fin' => substr((string) $fin, 0, 5),
            'matiere' => $slot->matiere->nom,
            'enseignant' => $enseignant,
            'salle' => $salle,
            'type' => $slot->type,
            'statut' => $statut,
            'exception' => $exception !== null,
            'motif' => $exception?->reason,
            'deplace' => $exception !== null && $exception->status === 'moved',
            'enseignant_remplace' => $exception !== null && $exception->new_teacher_id !== null,
            // Valeurs semestrielles normales (§18 : « Salle normalement : C02 »).
            'salle_normale' => $slot->room?->code,
            'debut_normal' => substr((string) $slot->start_time, 0, 5),
            'fin_normal' => substr((string) $slot->end_time, 0, 5),
            'enseignant_normal' => $slot->teacher?->nomComplet(),
        ];
    }
}
