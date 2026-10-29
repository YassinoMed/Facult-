<?php

namespace App\Modules\Schedule\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Schedule\Models\RemplacementCandidature;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Services\CandidatureRemplacementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Back-office — candidatures de remplacement pédagogique (LOT 09) :
 * registre des volontariats, acceptation pour un créneau (réutilise
 * le remplacement EDT transactionnel existant), écart motivé.
 */
class RemplacementCandidatureController extends Controller
{
    public function __construct(private readonly CandidatureRemplacementService $candidatures) {}

    /**
     * Registre filtrable (matière, statut) — consultable avec la
     * lecture des EDT.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('schedules.view');

        $faculteRestreinte = $request->user()->faculteRestreinteId();

        $candidatures = RemplacementCandidature::query()
            ->with(['teacher.departement', 'matiere'])
            ->when($faculteRestreinte !== null, fn ($q) => $q->whereHas(
                'teacher.departement',
                fn ($departement) => $departement->where('faculte_id', $faculteRestreinte)
            ))
            ->when($request->input('matiere_id'), fn ($q, $matiereId) => $q->where('matiere_id', (int) $matiereId))
            ->when($request->input('statut'), fn ($q, $statut) => $q->where('statut', $statut))
            ->latest()
            ->get();

        return response()->json(
            $candidatures->map(fn (RemplacementCandidature $candidature) => [
                'id' => $candidature->id,
                'enseignant' => $candidature->teacher?->nomComplet(),
                'matiere' => $candidature->matiere?->nom,
                'code' => $candidature->matiere?->code,
                'statut' => $candidature->statut,
                'message' => $candidature->message,
                'decide_le' => $candidature->decided_at?->toDateTimeString(),
            ])
        );
    }

    /**
     * Acceptation pour un créneau précis : le remplacement EDT
     * transactionnel s'exécute avec toutes ses gardes ; en cas
     * d'échec la candidature reste soumise et l'EDT inchangé.
     */
    public function accepter(Request $request, RemplacementCandidature $candidature): RedirectResponse|JsonResponse
    {
        Gate::authorize('schedules.manage');

        $valides = $request->validate([
            'schedule_slot_id' => ['required', 'integer', 'exists:schedule_slots,id'],
        ]);

        $slot = ScheduleSlot::with('schedule.groupe')->findOrFail((int) $valides['schedule_slot_id']);

        $this->verifierPerimetre($request, $candidature, $slot);

        try {
            $this->candidatures->accepter($candidature, $slot, $request->user()->id);
        } catch (ValidationException $exception) {
            if ($request->wantsJson()) {
                return response()->json(['erreur' => $exception->errors()], 422);
            }

            return redirect()->back()->withErrors($exception->errors());
        }

        if ($request->wantsJson()) {
            return response()->json(['succes' => true, 'candidature' => $candidature->fresh()->only(['id', 'statut', 'decided_at'])]);
        }

        return redirect()->back()->with('success', 'Candidature acceptée : remplacement appliqué au créneau.');
    }

    public function ecarter(Request $request, RemplacementCandidature $candidature): RedirectResponse|JsonResponse
    {
        Gate::authorize('schedules.manage');

        $this->verifierPerimetre($request, $candidature);

        $valides = $request->validate([
            'motif' => ['nullable', 'string', 'max:500'],
        ]);

        $this->candidatures->ecarter($candidature, $request->user()->id, $valides['motif'] ?? null);

        if ($request->wantsJson()) {
            return response()->json(['succes' => true, 'statut' => $candidature->fresh()->statut]);
        }

        return redirect()->back()->with('success', 'Candidature écartée.');
    }

    /**
     * Périmètre facultaire (même convention que ScheduleController) :
     * un admin_faculte ne voit et ne mute que les candidatures de ses
     * enseignants, et n'accepte que des créneaux de sa faculté.
     */
    private function verifierPerimetre(Request $request, RemplacementCandidature $candidature, ?ScheduleSlot $slot = null): void
    {
        $faculteRestreinte = $request->user()->faculteRestreinteId();

        if ($faculteRestreinte === null) {
            return;
        }

        $candidature->loadMissing('teacher');

        abort_unless(
            (int) $candidature->teacher?->faculteId() === $faculteRestreinte,
            404
        );

        if ($slot !== null) {
            $slot->loadMissing('schedule.groupe');

            abort_unless(
                (int) ($slot->schedule?->groupe?->faculte_id ?? 0) === $faculteRestreinte,
                404
            );
        }
    }
}
