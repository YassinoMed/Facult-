<?php

namespace App\Modules\ParcoursAcademique\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Modules\ParcoursAcademique\Models\ParcoursSimulation;
use App\Modules\ParcoursAcademique\Services\CreditParcoursService;
use App\Modules\ParcoursAcademique\Services\PlanParcoursService;
use App\Modules\ParcoursAcademique\Services\RisqueAcademiqueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API parcours académique (§28) : lecture seule, scopes
 * restreints, throttle, BOLA 404, sans PII sensible
 * (analyse/crédits/risques ne contiennent ni CIN ni contacts).
 */
class ParcoursApiController extends Controller
{
    public function parcours(Request $request, Etudiant $student, PlanParcoursService $plans): JsonResponse
    {
        $this->verifierPerimetre($request, $student);

        return response()->json(['data' => $plans->analyser($student)]);
    }

    public function credits(Request $request, Etudiant $student, CreditParcoursService $credits): JsonResponse
    {
        $this->verifierPerimetre($request, $student);

        return response()->json(['data' => $credits->creditsEtudiant($student)]);
    }

    public function risques(Request $request, Etudiant $student, RisqueAcademiqueService $risques): JsonResponse
    {
        $this->verifierPerimetre($request, $student);

        return response()->json(['data' => $risques->evaluer($student)]);
    }

    public function simulations(Request $request, Etudiant $student): JsonResponse
    {
        $this->verifierPerimetre($request, $student);

        $simulations = ParcoursSimulation::where('etudiant_id', $student->id)
            ->orderByDesc('id')->limit(50)->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'scenario' => $s->scenario,
                'resultat_final' => $s->resultat['resultat_final'] ?? null,
                'regle_reference' => $s->regle_reference,
                'cree_le' => $s->created_at,
            ]);

        return response()->json(['data' => $simulations]);
    }

    private function verifierPerimetre(Request $request, Etudiant $student): void
    {
        $faculteRestreinteId = $request->user()?->faculteRestreinteId();

        if ($faculteRestreinteId !== null && (int) $student->faculte_id !== (int) $faculteRestreinteId) {
            abort(404, 'Ressource introuvable dans votre périmètre.');
        }
    }
}
