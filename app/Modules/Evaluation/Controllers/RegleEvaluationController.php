<?php

namespace App\Modules\Evaluation\Controllers;

use App\Models\AnneeUniversitaire;
use App\Modules\Evaluation\Models\RegleEvaluation;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Règles d'évaluation versionnées (Phase 6 §8) : une règle = année
 * (+ programme optionnel) + seuils + compensations + arrondi + version.
 * L'ancienne version reste active jusqu'à bascule explicite — jamais
 * de recalcul silencieux de l'historique (snapshot en délibération).
 * Gates existantes, zéro permission créée.
 */
class RegleEvaluationController
{
    public function index(): View
    {
        return view('modules.evaluation.regles-index', [
            'regles' => RegleEvaluation::with(['anneeUniversitaire', 'programme'])
                ->orderByDesc('annee_universitaire_id')->orderByDesc('version')->get(),
            'annees' => AnneeUniversitaire::orderByDesc('libelle')->get(),
            'programmes' => Programme::orderBy('code')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'annee_universitaire_id' => ['required', 'exists:annees_universitaires,id'],
            'programme_id' => ['nullable', 'exists:programmes,id'],
            'seuil_validation' => ['required', 'numeric', 'min:0', 'max:20'],
            'seuil_rattrapage' => ['required', 'numeric', 'min:0', 'max:20', 'lt:seuil_validation'],
            'note_eliminatoire' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'compensation_ecue' => ['sometimes', 'boolean'],
            'compensation_ue' => ['sometimes', 'boolean'],
            'compensation_semestre' => ['sometimes', 'boolean'],
            'arrondi' => ['required', 'in:2dec,1dec,entier'],
        ]);

        // Isolation faculté (audit sécurité 04/10/2026) : une règle
        // rattachée à un programme d'une autre faculté est refusée.
        $programme = isset($donnees['programme_id']) ? Programme::findOrFail($donnees['programme_id']) : null;
        abort_unless($programme === null || $programme->faculte_id === null
            || $request->user()->peutAccederFaculte((int) $programme->faculte_id), 404);

        $version = ((int) RegleEvaluation::where('annee_universitaire_id', $donnees['annee_universitaire_id'])
            ->where('programme_id', $donnees['programme_id'] ?? null)
            ->max('version')) + 1;

        RegleEvaluation::create([
            'annee_universitaire_id' => $donnees['annee_universitaire_id'],
            'programme_id' => $donnees['programme_id'] ?? null,
            'seuil_validation' => $donnees['seuil_validation'],
            'seuil_rattrapage' => $donnees['seuil_rattrapage'],
            'note_eliminatoire' => $donnees['note_eliminatoire'] ?? null,
            'compensation_ecue' => (bool) ($donnees['compensation_ecue'] ?? true),
            'compensation_ue' => (bool) ($donnees['compensation_ue'] ?? true),
            'compensation_semestre' => (bool) ($donnees['compensation_semestre'] ?? true),
            'arrondi' => $donnees['arrondi'],
            'version' => $version,
            'actif' => true,
        ]);

        return redirect()->route('admin.evaluations.regles.index')
            ->with('succes', "Règles v{$version} créées (anciennes versions conservées).");
    }

    public function basculer(Request $request, RegleEvaluation $regle): RedirectResponse
    {
        $this->abortHorsPerimetre($request, $regle);

        $regle->update(['actif' => ! $regle->actif]);

        return redirect()->route('admin.evaluations.regles.index')
            ->with('succes', $regle->actif ? 'Version activée.' : 'Version désactivée (l’historique snapshoté reste intact).');
    }

    public function destroy(Request $request, RegleEvaluation $regle): RedirectResponse
    {
        $this->abortHorsPerimetre($request, $regle);

        if (! $regle->actif) {
            $regle->delete();

            return redirect()->route('admin.evaluations.regles.index')
                ->with('succes', 'Version inactive supprimée.');
        }

        return redirect()->back()->withErrors([
            'regle' => 'Version active : désactivez-la d’abord (l’historique ne doit jamais perdre sa règle).',
        ]);
    }

    /**
     * Isolation faculté (audit sécurité 04/10/2026) : une règle rattachée
     * au programme d'une autre faculté n'est pas manipulable (404).
     */
    private function abortHorsPerimetre(Request $request, RegleEvaluation $regle): void
    {
        $regle->loadMissing('programme');
        $faculteId = $regle->programme?->faculte_id;

        abort_unless($faculteId === null || $request->user()->peutAccederFaculte((int) $faculteId), 404);
    }
}
