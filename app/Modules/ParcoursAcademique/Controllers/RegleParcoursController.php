<?php

namespace App\Modules\ParcoursAcademique\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ParcoursAcademique\Models\ParcoursRegle;
use App\Modules\ParcoursAcademique\Requests\StoreParcoursRegleRequest;
use App\Modules\ParcoursAcademique\Services\ParcoursRegleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Règles configurables versionnées : lecture + versionnement.
 * Anciennes versions conservées (décisions rejouables).
 */
class RegleParcoursController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('parcours.regles');

        $clone = null;
        if (is_string($request->query('code')) && $request->query('code') !== '') {
            $clone = ParcoursRegle::where('code', $request->query('code'))
                ->orderByDesc('version')->first();
        }

        return view('modules.parcours-academique.regles-index', [
            'regles' => ParcoursRegle::orderBy('code')->orderByDesc('version')->paginate(20)->withQueryString(),
            'clone' => $clone,
        ]);
    }

    public function store(StoreParcoursRegleRequest $request, ParcoursRegleService $regles): RedirectResponse
    {
        Gate::authorize('parcours.regles');

        $donnees = $request->validated();
        $criteres = $donnees['criteres'] ?? [];
        if ($criteres === [] && is_string($request->input('criteres_json')) && trim((string) $request->input('criteres_json')) !== '') {
            $decodes = json_decode((string) $request->input('criteres_json'), true);
            abort_unless(is_array($decodes), 422, 'Critères JSON invalides.');
            $criteres = $decodes;
        }
        $regles->versionner(
            $donnees['code'], $donnees['libelle'], $criteres,
            $donnees['annee_universitaire_id'] ?? null, $donnees['programme_id'] ?? null
        );

        return back()->with('success', 'Nouvelle version de règle créée.');
    }
}
