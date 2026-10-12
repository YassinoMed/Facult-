<?php

namespace App\Modules\Enseignement\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Matiere;
use App\Modules\Enseignement\Models\ComposanteEvaluation;
use App\Modules\Enseignement\Services\EvaluationService;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Évaluation des ECUE (§23-24) : composantes (somme 100) + responsable.
 */
class EvaluationController extends Controller
{
    public function __construct(
        private readonly EvaluationService $evaluations,
    ) {}

    public function edit(Request $request, Matiere $matiere): View
    {
        abort_unless($matiere->ue_id !== null, 404);
        $this->abortHorsPerimetre($request, $matiere);

        return view('modules.enseignement.evaluation', [
            'matiere' => $matiere->loadMissing('composantes'),
            'enseignants' => Teacher::where('actif', true)->orderBy('nom')->get(),
        ]);
    }

    public function composantes(Request $request, Matiere $matiere): RedirectResponse
    {
        abort_unless($matiere->ue_id !== null, 404);
        $this->abortHorsPerimetre($request, $matiere);

        $valide = $request->validate([
            'composantes' => 'required|array|min:1|max:10',
            'composantes.*.code' => 'required|string|max:20',
            'composantes.*.libelle' => 'required|string|max:100',
            'composantes.*.pourcentage' => 'required|numeric|min:0|max:100',
        ]);

        try {
            $this->evaluations->definirComposantes($matiere, array_values($valide['composantes']), (int) $request->user()->id);
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['composantes' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin.enseignements.evaluation.edit', $matiere)
            ->with('success', 'Composantes enregistrées (total 100 %).');
    }

    public function responsable(Request $request, Matiere $matiere): RedirectResponse
    {
        abort_unless($matiere->ue_id !== null, 404);
        $this->abortHorsPerimetre($request, $matiere);

        $teacherId = $request->validate([
            'responsable_teacher_id' => 'nullable|exists:teachers,id',
        ])['responsable_teacher_id'] ?? null;

        try {
            $this->evaluations->definirResponsable($matiere, $teacherId !== null ? (int) $teacherId : null, (int) $request->user()->id);
        } catch (\DomainException $e) {
            return redirect()->back()->withErrors(['responsable' => $e->getMessage()]);
        }

        return redirect()->route('admin.enseignements.evaluation.edit', $matiere)
            ->with('success', 'Responsable enregistré.');
    }

    public function detruireComposante(Request $request, ComposanteEvaluation $composante): RedirectResponse
    {
        $matiere = $composante->matiere()->firstOrFail();
        $this->abortHorsPerimetre($request, $matiere);

        $composante->delete();

        return redirect()->route('admin.enseignements.evaluation.edit', $matiere->id)
            ->with('success', 'Composante supprimée (recalculez le total à 100 %).');
    }

    /**
     * Isolation faculté (audit sécurité 04/10/2026) : les modalités
     * d'évaluation d'un ECUE d'une autre faculté sont refusées (404).
     */
    private function abortHorsPerimetre(Request $request, Matiere $matiere): void
    {
        $faculteId = $matiere->faculteId();

        abort_unless($faculteId === null || $request->user()->peutAccederFaculte($faculteId), 404);
    }
}
