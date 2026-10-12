<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Models\Presence;
use App\Modules\Attendance\Models\SeanceAppel;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Back-office — appels de présence enregistrés (lecture seule).
 * Les modifications passent par les circuits existants (relevés : store
 * admin ; présences enseignants : validation/correction motivées).
 * Corrections des relevés d'appel : route enseignant `appel.corriger`
 * (motif + historique) ou ré-enregistrement admin.
 */
class AppelsController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('attendance.view'), 403);

        $faculteId = $request->user()->faculteRestreinteId();

        $appels = SeanceAppel::with(['slot.matiere', 'slot.schedule.groupe', 'enseignant'])
            ->when($faculteId !== null, fn ($q) => $q->whereHas('slot.schedule.groupe', fn ($g) => $g->where('faculte_id', $faculteId)))
            ->when($request->filled('date'), fn ($q) => $q->whereDate('date', $request->input('date')))
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->input('statut')))
            ->when($request->filled('groupe_id'), fn ($q) => $q->whereHas('slot.schedule', fn ($s) => $s->where('groupe_id', $request->input('groupe_id'))))
            ->orderByDesc('date')
            ->paginate(15)
            ->withQueryString();

        return view('admin.appels.index', [
            'appels' => $appels,
        ]);
    }

    public function show(Request $request, SeanceAppel $appel): View
    {
        abort_unless($request->user()->can('attendance.view'), 403);

        $appel->loadMissing(['slot.matiere', 'slot.room', 'slot.schedule.groupe', 'enseignant']);

        $faculteId = $request->user()->faculteRestreinteId();
        $faculteAppel = $appel->slot?->schedule?->groupe?->faculte_id;
        abort_unless($faculteId === null || $faculteAppel === null || (int) $faculteAppel === $faculteId, 404);

        $releves = Presence::with('etudiant')
            ->where('schedule_slot_id', $appel->schedule_slot_id)
            ->whereDate('date', $appel->date->toDateString())
            ->orderBy('id')
            ->get();

        return view('admin.appels.show', compact('appel', 'releves'));
    }
}
