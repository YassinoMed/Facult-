<?php

namespace App\Http\Controllers\Enseignant;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\TeacherDocument;
use App\Models\TeacherReclamation;
use App\Models\TeacherRequest;
use App\Modules\Announcements\Models\Annonce;
use App\Modules\Attendance\Models\TeacherPointage;
use App\Modules\Calendar\Services\AgendaService;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Schedule\Models\Teacher;
use App\Services\NotificationService;
use App\Support\Modules\ModuleRegistry;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Tableau de bord enseignant — calculé exclusivement depuis les données réelles :
 * - l'enseignant est résolu depuis la session (anti-IDOR) ;
 * - requêtes bornées et ciblées (séances du jour, 5 prochaines gardes, compteurs d'activités) ;
 * - aucun chiffre fictif : tout indicateur provient de la base de données.
 */
class TableauDeBordController extends Controller
{
    public function index(Request $request, NotificationService $notifications): View
    {
        $enseignant = Teacher::with('departement.faculte')->findOrFail($request->session()->get('enseignant_auth_id'));
        $modules = app(ModuleRegistry::class);

        $seancesDuJour = $modules->estActif('schedule') ? $this->seancesDuJour($enseignant) : [];
        $prochainesGardes = $modules->estActif('evaluation') ? $this->prochainesGardes($enseignant) : collect();

        // Widgets transverses : strictement conditionnés à l'activation du
        // module, et filtrés côté serveur (jamais de brouillon ni d'annonce
        // d'une autre faculté dans la vue).
        $prochainesEcheances = $modules->estActif('calendar')
            ? app(AgendaService::class)->agendaPourEnseignant(
                $enseignant,
                Carbon::today()->toDateString(),
                Carbon::today()->addWeeks(8)->toDateString(),
                3,
            )
            : [];

        $annoncesEnseignant = $modules->estActif('announcements')
            ? Annonce::visiblesPourEnseignant($enseignant)
                ->orderByDesc('epinglee')
                ->latest('publiee_a')
                ->limit(3)
                ->get()
            : collect();

        $notificationsNonLues = AppNotification::where('teacher_id', $enseignant->id)->whereNull('read_at')->count();

        $demandesEnCours = TeacherRequest::where('teacher_id', $enseignant->id)
            ->whereIn('statut', ['soumise', 'en_cours', 'en_attente_complement'])
            ->count();

        $reclamationsEnAttente = TeacherReclamation::where('teacher_id', $enseignant->id)
            ->whereIn('statut', ['ouverte', 'en_cours'])
            ->count();

        $documentsRecents = TeacherDocument::where('teacher_id', $enseignant->id)
            ->latest()
            ->limit(4)
            ->get();

        $derniersPointages = TeacherPointage::where('teacher_id', $enseignant->id)
            ->latest('horodatage')
            ->limit(3)
            ->get();

        return view('enseignant.dashboard', [
            'enseignant' => $enseignant,
            'seancesDuJour' => $seancesDuJour,
            'prochainesGardes' => $prochainesGardes,
            'prochainesEcheances' => $prochainesEcheances,
            'annoncesEnseignant' => $annoncesEnseignant,
            'notificationsNonLues' => $notificationsNonLues,
            'demandesEnCours' => $demandesEnCours,
            'reclamationsEnAttente' => $reclamationsEnAttente,
            'documentsRecents' => $documentsRecents,
            'derniersPointages' => $derniersPointages,
        ]);
    }

    /**
     * @return array<int, array{id: int, debut: string, fin: string, matiere: string, salle: ?string, type: string, statut: string}>
     */
    private function seancesDuJour(Teacher $enseignant): array
    {
        $jour = strtolower(Carbon::now()->format('l'));
        $aujourdhui = Carbon::today()->toDateString();

        return $enseignant->slots()
            ->where('day_of_week', $jour)
            ->where('status', '!=', 'cancelled')
            ->with(['matiere', 'room', 'exceptions' => fn ($q) => $q->whereDate('exception_date', $aujourdhui)->with('nouvelleSalle')])
            ->orderBy('start_time')
            ->get()
            ->map(function ($slot) {
                $exception = $slot->exceptions->first();

                if ($exception !== null && $exception->status === 'cancelled') {
                    return null;
                }

                return [
                    'id' => $slot->id,
                    'debut' => substr((string) ($exception?->new_start_time ?? $slot->start_time), 0, 5),
                    'fin' => substr((string) ($exception?->new_end_time ?? $slot->end_time), 0, 5),
                    'matiere' => $slot->matiere?->nom ?? 'Matière non définie',
                    'salle' => $exception?->nouvelleSalle?->code ?? $slot->room?->code,
                    'type' => $slot->type,
                    'statut' => $exception?->status ?? $slot->status,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Surveillance>
     */
    private function prochainesGardes(Teacher $enseignant)
    {
        return $enseignant->surveillances()
            ->where('evaluation_surveillants.statut', '!=', 'annule')
            ->whereHas('epreuve', fn ($q) => $q->where('statut', '!=', 'cancelled')->whereDate('date', '>=', Carbon::today()))
            ->with(['epreuve.matiere', 'epreuve.salle', 'epreuve.session'])
            ->join('evaluation_epreuves', 'evaluation_surveillants.epreuve_id', '=', 'evaluation_epreuves.id')
            ->orderBy('evaluation_epreuves.date')
            ->orderBy('evaluation_epreuves.heure_debut')
            ->select('evaluation_surveillants.*')
            ->limit(5)
            ->get();
    }
}
