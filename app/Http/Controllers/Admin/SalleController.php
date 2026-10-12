<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Schedule\Models\Room;
use App\Support\Recherche;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Référentiel salles (§10) : globales, capacité suivie pour la
 * détection de conflits de capacité (§12).
 */
class SalleController extends Controller
{
    public function index(Request $request): View
    {
        $salles = Room::withCount('slots')
            ->when($request->input('search'), function ($q, $s) {
                $motif = Recherche::contient(trim($s));

                return $q->where(
                    fn ($w) => $w->where('code', 'like', $motif)->orWhere('nom', 'like', $motif)
                );
            })
            ->orderBy('code')
            ->paginate(12)
            ->withQueryString();

        return view('admin.salles.index', compact('salles'));
    }

    public function create(): View
    {
        return view('admin.salles.form');
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'code' => 'required|string|max:20|unique:rooms,code',
            'nom' => 'nullable|string|max:100',
            'capacite' => 'nullable|integer|min:1|max:10000',
            'min_surveillants' => 'nullable|integer|min:1|max:20',
            'ratio_surveillants' => 'nullable|integer|min:1|max:500',
            'campus' => 'nullable|string|max:100',
            'batiment' => 'nullable|string|max:100',
            'etage' => 'nullable|string|max:20',
            'faculte_id' => 'nullable|exists:facultes,id',
        ]);

        // Isolation faculté (audit sécurité 04/10/2026) : un compte
        // restreint rattache la salle à SA faculté (jamais à une autre,
        // jamais de salle globale) — cible explicite étrangère → 404.
        $restreinte = $request->user()->faculteRestreinteId();
        if ($restreinte !== null) {
            abort_unless(! isset($donnees['faculte_id']) || (int) $donnees['faculte_id'] === $restreinte, 404);
            $donnees['faculte_id'] = $restreinte;
        }

        Room::create($donnees + ['actif' => true]);

        return redirect()->route('admin.salles.index')
            ->with('success', 'Salle créée.');
    }

    public function edit(Room $salle): View
    {
        return view('admin.salles.form', ['salle' => $salle]);
    }

    public function update(Request $request, Room $salle): RedirectResponse|JsonResponse
    {
        // Isolation faculté (audit sécurité 04/10/2026) : salle d'une
        // autre faculté → 404 (les salles globales restent partagées).
        $this->abortHorsPerimetre($request, $salle);

        $donnees = $request->validate([
            'code' => 'sometimes|required|string|max:20|unique:rooms,code,'.$salle->id,
            'nom' => 'nullable|string|max:100',
            'capacite' => 'nullable|integer|min:1|max:10000',
            'min_surveillants' => 'nullable|integer|min:1|max:20',
            'ratio_surveillants' => 'nullable|integer|min:1|max:500',
            'campus' => 'nullable|string|max:100',
            'batiment' => 'nullable|string|max:100',
            'etage' => 'nullable|string|max:20',
            'faculte_id' => 'nullable|exists:facultes,id',
            'actif' => 'sometimes|boolean',
        ]);

        $salle->update($donnees);

        // Inline editing (fetch) : réponse JSON légère.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Salle mise à jour.',
                'salle' => $salle->fresh()->only(['id', 'code', 'nom', 'capacite', 'min_surveillants', 'ratio_surveillants']),
            ]);
        }

        return redirect()->route('admin.salles.index')
            ->with('success', 'Salle mise à jour.');
    }

    public function destroy(Request $request, Room $salle): RedirectResponse
    {
        $this->abortHorsPerimetre($request, $salle);

        if ($salle->slots()->exists()) {
            return redirect()->back()->withErrors([
                'salle' => 'Impossible : cette salle est utilisée par des créneaux.',
            ]);
        }

        $salle->delete();

        return redirect()->route('admin.salles.index')
            ->with('success', 'Salle supprimée.');
    }

    /**
     * Isolation faculté (audit sécurité 04/10/2026) : `faculte_id` null =
     * salle partagée (convention du projet, cf. CalendrierPolicy::update).
     */
    private function abortHorsPerimetre(Request $request, Room $salle): void
    {
        $restreinte = $request->user()->faculteRestreinteId();

        abort_unless($salle->faculte_id === null
            || $restreinte === null
            || (int) $salle->faculte_id === $restreinte, 404);
    }
}
