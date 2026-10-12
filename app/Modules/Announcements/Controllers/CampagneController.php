<?php

namespace App\Modules\Announcements\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Announcements\Models\Campagne;
use App\Modules\Announcements\Services\CampagneService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CampagneController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Campagne::class);

        $query = Campagne::orderByDesc('id');

        if ($faculteId = $request->user()->faculteRestreinteId()) {
            $query->where(fn ($w) => $w->whereNull('faculte_id')->orWhere('faculte_id', $faculteId));
        }

        return view('modules.announcements.campagnes-index', ['campagnes' => $query->paginate(15)]);
    }

    public function store(Request $request, CampagneService $service): RedirectResponse
    {
        Gate::authorize('create', Campagne::class);

        $donnees = $request->validate([
            'titre' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:2000'],
            'audience' => ['required', 'in:etudiants,personnel,tous'],
            'faculte_id' => ['nullable', 'exists:facultes,id'],
            'programme_id' => ['nullable', 'exists:programmes,id'],
            'groupe_id' => ['nullable', 'exists:groups,id'],
            'planifiee_a' => ['nullable', 'date', 'after:now'],
        ]);

        $campagne = Campagne::create([
            ...$donnees,
            'statut' => 'brouillon',
            'cle_unique' => $service->nouvelleCle(),
            'created_by' => $request->user()->id,
        ]);

        $service->estimer($campagne);

        return back()->with('success', "Campagne créée — audience estimée : {$campagne->fresh()->destinataires_estimes}.");
    }

    public function planifier(Request $request, Campagne $campagne): RedirectResponse
    {
        Gate::authorize('update', $campagne);

        $donnees = $request->validate(['planifiee_a' => ['required', 'date', 'after:now']]);

        $campagne->update(['statut' => 'planifiee', 'planifiee_a' => $donnees['planifiee_a']]);

        return back()->with('success', 'Campagne planifiée.');
    }

    public function envoyer(Request $request, Campagne $campagne, CampagneService $service): RedirectResponse
    {
        Gate::authorize('update', $campagne);

        abort_unless($campagne->faculte_id === null || $request->user()->peutAccederFaculte((int) $campagne->faculte_id), 404);

        try {
            $campagne = $service->envoyer($campagne);
        } catch (DomainException $e) {
            return back()->withErrors(['campagne' => $e->getMessage()]);
        }

        return back()->with('success', "Campagne envoyée à {$campagne->envoyes} destinataire(s).");
    }
}
