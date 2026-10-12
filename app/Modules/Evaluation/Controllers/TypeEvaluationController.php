<?php

namespace App\Modules\Evaluation\Controllers;

use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\TypeEvaluation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Référentiel des types d'évaluation (Phase 5 §26) : global (aucun
 * périmètre faculté, comme les types d'enseignement), géré via
 * evaluations.manage. Suppression refusée si des épreuves l'utilisent.
 */
class TypeEvaluationController
{
    public function index(): View
    {
        return view('modules.evaluation.types-index', [
            'types' => TypeEvaluation::orderBy('code')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9_]+$/', 'unique:evaluation_types_evaluation,code'],
            'libelle' => ['required', 'string', 'max:100'],
            'coefficient_defaut' => ['nullable', 'numeric', 'gt:0', 'max:99'],
            'duree_defaut_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
        ]);

        TypeEvaluation::create($donnees + ['actif' => true]);

        return redirect()->route('admin.evaluations.types.index')
            ->with('succes', 'Type d’évaluation créé.');
    }

    public function update(Request $request, TypeEvaluation $type): RedirectResponse
    {
        $donnees = $request->validate([
            'libelle' => ['required', 'string', 'max:100'],
            'coefficient_defaut' => ['nullable', 'numeric', 'gt:0', 'max:99'],
            'duree_defaut_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'actif' => ['sometimes', 'boolean'],
        ]);

        $type->update($donnees + ['actif' => (bool) ($donnees['actif'] ?? false)]);

        return redirect()->route('admin.evaluations.types.index')
            ->with('succes', 'Type d’évaluation mis à jour.');
    }

    public function destroy(TypeEvaluation $type): RedirectResponse
    {
        if (Epreuve::where('type_evaluation', $type->code)->exists()) {
            return redirect()->back()->withErrors([
                'type' => 'Type utilisé par des épreuves : désactivez-le plutôt.',
            ]);
        }

        $type->delete();

        return redirect()->route('admin.evaluations.types.index')
            ->with('succes', 'Type d’évaluation supprimé.');
    }
}
