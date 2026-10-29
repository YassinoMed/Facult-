<?php

namespace App\Http\Controllers\Enseignant;

use App\Http\Controllers\Controller;
use App\Modules\Schedule\Models\RemplacementCandidature;
use App\Modules\Schedule\Models\Teacher;
use App\Modules\Schedule\Services\CandidatureRemplacementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Portail enseignant — candidatures de remplacement pédagogique
 * (LOT 09) : déclaration de volontariat par matière, retrait libre.
 * Le contrôle d'accès est la session enseignant (`enseignant.auth`) ;
 * la mutation de l'EDT n'a jamais lieu ici.
 */
class CandidaturesRemplacementController extends Controller
{
    public function __construct(private readonly CandidatureRemplacementService $candidatures) {}

    /**
     * Mes candidatures (tous statuts, plus récentes d'abord).
     */
    public function index(Request $request): JsonResponse
    {
        $teacher = Teacher::findOrFail($request->session()->get('enseignant_auth_id'));

        return response()->json(
            RemplacementCandidature::where('teacher_id', $teacher->id)
                ->with('matiere')
                ->latest()
                ->get()
                ->map(fn (RemplacementCandidature $candidature) => [
                    'id' => $candidature->id,
                    'matiere' => $candidature->matiere?->nom,
                    'code' => $candidature->matiere?->code,
                    'statut' => $candidature->statut,
                    'message' => $candidature->message,
                    'decide_le' => $candidature->decided_at?->toDateTimeString(),
                ])
        );
    }

    public function store(Request $request): RedirectResponse
    {
        $teacher = Teacher::findOrFail($request->session()->get('enseignant_auth_id'));

        $valides = $request->validate([
            'matiere_id' => ['required', 'integer', 'exists:matieres,id'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->candidatures->deposer($teacher, $valides);
        } catch (ValidationException $exception) {
            return redirect()->back()->withErrors($exception->errors());
        }

        return redirect()->back()->with('success', 'Candidature de remplacement enregistrée.');
    }

    public function destroy(Request $request, RemplacementCandidature $candidature): RedirectResponse
    {
        $teacher = Teacher::findOrFail($request->session()->get('enseignant_auth_id'));

        try {
            $this->candidatures->retirer($candidature, $teacher);
        } catch (ValidationException $exception) {
            return redirect()->back()->withErrors($exception->errors());
        }

        return redirect()->back()->with('success', 'Candidature retirée.');
    }
}
