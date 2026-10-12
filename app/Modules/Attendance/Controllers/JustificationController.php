<?php

namespace App\Modules\Attendance\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Modules\Attendance\Models\Justification;
use App\Modules\Attendance\Models\Presence;
use App\Modules\Attendance\Requests\DeposerJustificationRequest;
use App\Modules\Attendance\Services\AssiduiteService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class JustificationController extends Controller
{
    /**
     * File d'attente des justifications (scolarité, scopée faculté).
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Justification::class);

        $query = Justification::with(['etudiant.faculte', 'presence'])
            ->when($request->input('statut'), fn ($q, $statut) => $q->where('statut', $statut))
            ->latest();

        if ($faculteId = $request->user()->faculteRestreinteId()) {
            $query->whereHas('etudiant', fn ($q) => $q->where('faculte_id', $faculteId));
        }

        return view('modules.attendance.justifications', [
            'justifications' => $query->paginate(15)->withQueryString(),
        ]);
    }

    public function valider(Request $request, Justification $justification, AssiduiteService $service): RedirectResponse
    {
        Gate::authorize('validate', $justification);

        $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'commentaire_validation' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $service->validerJustification(
                $justification,
                (int) $request->user()->id,
                $request->input('decision') === 'approved',
                $request->input('commentaire_validation'),
            );
        } catch (DomainException $e) {
            return back()->withErrors(['justification' => $e->getMessage()]);
        }

        return back()->with('success', 'Justification traitée.');
    }

    /**
     * Dépôt étudiant : SA justification uniquement (session-scopé).
     */
    public function deposer(DeposerJustificationRequest $request, Presence $presence, AssiduiteService $service): RedirectResponse
    {
        $etudiant = Etudiant::findOrFail($request->session()->get('etudiant_auth_id'));

        $valides = $request->validated();

        $chemin = null;
        $nom = null;

        if ($request->hasFile('piece')) {
            $fichier = $request->file('piece');
            $nom = $fichier->getClientOriginalName();
            $chemin = $fichier->storeAs(
                "justifications/etudiant_{$etudiant->id}",
                (string) Str::uuid().'.'.$fichier->getClientOriginalExtension(),
                'local'
            );
        }

        $service->deposerJustification($presence, $etudiant, $valides['motif'], $chemin, $nom);

        return back()->with('success', 'Justification déposée, en attente de vérification.');
    }
}
