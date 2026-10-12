<?php

namespace App\Modules\Calendar\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Modules\Calendar\Models\EvenementCalendrier;
use App\Modules\Calendar\Requests\StoreEvenementRequest;
use App\Modules\Calendar\Services\AgendaService;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CalendrierController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', EvenementCalendrier::class);

        $query = EvenementCalendrier::with(['anneeUniversitaire', 'faculte'])
            ->orderBy('date_debut');

        if ($faculteId = $request->user()->faculteRestreinteId()) {
            $query->where(fn ($w) => $w->whereNull('faculte_id')->orWhere('faculte_id', $faculteId));
        }

        return view('modules.calendar.index', [
            'evenements' => $query->paginate(20)->withQueryString(),
            'annees' => AnneeUniversitaire::orderByDesc('libelle')->get(),
        ]);
    }

    public function store(StoreEvenementRequest $request): RedirectResponse
    {
        $donnees = $request->validated();

        // Isolation faculté (audit sécurité 04/10/2026) : l'événement est
        // publié immédiatement — chaque cible (faculté/programme/groupe)
        // doit relever du périmètre de l'utilisateur (scope → 404).
        $faculteCible = collect([
            $donnees['faculte_id'] ?? null,
            isset($donnees['programme_id']) ? Programme::find($donnees['programme_id'])?->faculte_id : null,
            isset($donnees['groupe_id']) ? Group::find($donnees['groupe_id'])?->faculte_id : null,
        ])->filter()->first();

        abort_unless($faculteCible === null
            || $request->user()->peutAccederFaculte((int) $faculteCible), 404);

        EvenementCalendrier::create([...$donnees, 'statut' => 'publie', 'created_by' => $request->user()->id]);

        return back()->with('success', 'Événement publié au calendrier.');
    }

    public function destroy(Request $request, EvenementCalendrier $evenement): RedirectResponse
    {
        Gate::authorize('delete', $evenement);

        $evenement->delete();

        return back()->with('success', 'Événement supprimé.');
    }

    public function agenda(Request $request, AgendaService $agenda): View
    {
        $etudiant = Etudiant::findOrFail($request->session()->get('etudiant_auth_id'));
        $debut = $request->input('debut', now()->startOfMonth()->format('Y-m-d'));
        $fin = $request->input('fin', now()->addMonths(2)->endOfMonth()->format('Y-m-d'));

        return view('modules.calendar.agenda', [
            'elements' => $agenda->agendaPourEtudiant($etudiant, $debut, $fin),
            'debut' => $debut,
            'fin' => $fin,
        ]);
    }
}
