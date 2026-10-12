<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AssignmentItem;
use App\Models\AssignmentSimulation;
use App\Models\Etudiant;
use App\Modules\Schedule\Models\Group;
use App\Services\Affectations\AssignmentConfirmationService;
use App\Services\Affectations\AssignmentSimulationService;
use App\Services\AuditService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API v1 affectations (§26) : simulation sans écriture, lecture,
 * confirmation par groupe et par tranches (client répète tant que
 * `terminee=false`). Scopes `assignments:read|write`, throttle,
 * périmètre faculté (404 hors périmètre, anti-BOLA), audit.
 */
class AssignmentApiController extends Controller
{
    public function __construct(
        private readonly AssignmentSimulationService $simulations,
        private readonly AssignmentConfirmationService $confirmations,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $simulations = AssignmentSimulation::orderByDesc('id')
            ->limit(100)
            ->get()
            ->filter(fn (AssignmentSimulation $s) => $this->dansPerimetre($request, $s))
            ->values();

        return response()->json([
            'data' => $simulations->map(fn (AssignmentSimulation $s) => $this->resume($s))->all(),
        ]);
    }

    public function simuler(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'annee_id' => ['required', 'integer', 'exists:annees_universitaires,id'],
            'groupe_ids' => ['required', 'array', 'min:1', 'max:500'],
            'groupe_ids.*' => ['integer', 'exists:groups,id'],
            'etudiant_ids' => ['required', 'array', 'min:1', 'max:5000'],
            'etudiant_ids.*' => ['integer', 'min:1'],
            'seed' => ['nullable', 'string', 'max:16'],
        ]);

        $restreinte = $request->user()->faculteRestreinteId();
        $groupes = Group::whereIn('id', $donnees['groupe_ids'])
            ->when($restreinte !== null, fn ($q) => $q->where('faculte_id', $restreinte))
            ->pluck('id')->all();

        abort_if($groupes === [], 404);

        $etudiants = Etudiant::whereIn('id', $donnees['etudiant_ids'])
            ->when($restreinte !== null, fn ($q) => $q->where('faculte_id', $restreinte))
            ->pluck('id')->all();

        abort_if($etudiants === [], 422, 'Aucun étudiant candidat dans le périmètre.');

        $simulation = $this->simulations->simuler([
            'annee_id' => (int) $donnees['annee_id'],
            'groupe_ids' => $groupes,
            'etudiant_ids' => $etudiants,
            'seed' => $donnees['seed'] ?? null,
        ], (int) $request->user()->id, $restreinte);

        return response()->json(['data' => $this->resume($simulation->fresh())], 201);
    }

    public function show(Request $request, AssignmentSimulation $simulation): JsonResponse
    {
        $this->verifierPerimetre($request, $simulation);

        return response()->json(['data' => $this->resume($simulation) + [
            'items' => AssignmentItem::where('simulation_id', $simulation->id)
                ->orderBy('id')->limit(500)->get()->map(fn (AssignmentItem $i) => [
                    'etudiant_id' => $i->etudiant_id,
                    'groupe_propose_id' => $i->groupe_propose_id,
                    'flexibilite' => $i->flexibilite,
                    'statut' => $i->statut,
                ])->all(),
        ]]);
    }

    public function student(Request $request, AssignmentSimulation $simulation, int $etudiant): JsonResponse
    {
        $this->verifierPerimetre($request, $simulation);

        $item = AssignmentItem::where('simulation_id', $simulation->id)
            ->where('etudiant_id', $etudiant)->firstOrFail();

        return response()->json(['data' => [
            'etudiant_id' => $item->etudiant_id,
            'groupe_propose_id' => $item->groupe_propose_id,
            'flexibilite' => $item->flexibilite,
            'statut' => $item->statut,
            'raison' => $item->raison,
            'candidats' => $item->candidats,
        ]]);
    }

    public function confirmer(Request $request, AssignmentSimulation $simulation, int $groupe): JsonResponse
    {
        $this->verifierPerimetre($request, $simulation);
        abort_unless(Group::whereKey($groupe)->exists(), 404);

        $limite = min(500, max(1, (int) $request->input('tranche', 200)));

        try {
            $resultat = $this->confirmations->confirmerGroupe($simulation, $groupe, (int) $request->user()->id, $limite);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $resultat + ['terminee' => $resultat['termine']]]);
    }

    /** @return array<string, mixed> */
    private function resume(AssignmentSimulation $simulation): array
    {
        return [
            'id' => $simulation->id,
            'reference' => $simulation->reference,
            'statut' => $simulation->statut,
            'seed' => $simulation->seed,
            'candidats' => $simulation->candidats,
            'affectes' => $simulation->affectes,
            'non_affectes' => $simulation->non_affectes,
            'metriques' => $simulation->metriques,
        ];
    }

    private function dansPerimetre(Request $request, AssignmentSimulation $simulation): bool
    {
        $restreinte = $request->user()->faculteRestreinteId();

        if ($restreinte === null) {
            return true;
        }

        return Group::whereIn('id', $simulation->parametres['groupe_ids'] ?? [])
            ->where('faculte_id', '!=', $restreinte)->doesntExist();
    }

    private function verifierPerimetre(Request $request, AssignmentSimulation $simulation): void
    {
        abort_unless($this->dansPerimetre($request, $simulation), 404);
    }
}
