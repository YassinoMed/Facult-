<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ElementRecent;
use App\Models\HistoriqueAcademique;
use App\Modules\Calendar\Models\EvenementCalendrier;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\Tasks\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Cockpit administratif (§8) : ce qui nécessite une intervention
 * humaine MAINTENANT. Composition de données existantes (tâches,
 * agenda, audit, qualité) — aucune table cockpit dédiée.
 */
class CockpitController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('cockpit.view');

        $faculteId = $request->user()->faculteRestreinteId();
        $tachesBase = Task::query()
            ->when($faculteId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('faculte_id')->orWhere('faculte_id', $faculteId)));

        $aTraiter = (clone $tachesBase)->whereIn('statut', ['pending', 'in_progress', 'waiting'])->count();
        $urgentes = (clone $tachesBase)->where('priorite', 'critical')
            ->whereIn('statut', ['pending', 'in_progress', 'waiting', 'expired'])->orderBy('echeance_a')->limit(8)->get();
        $miennes = (clone $tachesBase)->where('assigne_a', $request->user()->id)
            ->whereIn('statut', ['pending', 'in_progress', 'waiting'])->orderBy('echeance_a')->limit(8)->get();

        $aujourdhui = EvenementCalendrier::where('statut', 'publie')
            ->whereDate('date_debut', '<=', now()->format('Y-m-d'))
            ->where(fn ($q) => $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', now()->format('Y-m-d')))
            ->when($faculteId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('faculte_id')->orWhere('faculte_id', $faculteId)))
            ->orderBy('date_debut')->limit(8)->get();

        $demandesEcheance = $request->user()->can('demandes.view')
            ? OnlineRequest::with(['etudiant', 'type'])
                ->whereIn('status', ['submitted', 'received', 'in_review'])
                ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
                ->get()->filter(fn ($d) => $d->enRetard())->take(8)
            : collect();

        $activite = HistoriqueAcademique::with(['etudiant', 'utilisateur'])
            ->when($faculteId !== null, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteId)))
            ->latest()->limit(10)->get();

        return view('admin.cockpit.index', [
            'aTraiter' => $aTraiter,
            'urgentes' => $urgentes,
            'miennes' => $miennes,
            'aujourdhui' => $aujourdhui,
            'demandesEcheance' => $demandesEcheance,
            'activite' => $activite,
            'recents' => ElementRecent::where('user_id', $request->user()->id)->latest('updated_at')->limit(6)->get(),
        ]);
    }
}
