<?php

namespace App\Modules\Enseignement\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AnneeUniversitaire;
use App\Models\GradeEnseignant;
use App\Modules\Enseignement\Models\SeuilService;
use App\Modules\Enseignement\Models\TypeEnseignement;
use App\Modules\Enseignement\Services\QualitePedagogiqueService;
use App\Modules\Enseignement\Services\ServiceEnseignantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Services enseignants (§18-22), types (§6), qualité (§38) : calculs
 * serveur, seuils configurables, alertes sur/sous-charge.
 */
class ServiceController extends Controller
{
    public function __construct(
        private readonly ServiceEnseignantService $services,
        private readonly QualitePedagogiqueService $qualite,
    ) {}

    public function services(Request $request): View
    {
        $anneeId = $request->input('annee_universitaire_id') !== null
            && $request->input('annee_universitaire_id') !== ''
            ? (int) $request->input('annee_universitaire_id') : null;

        return view('modules.enseignement.services', [
            'tableau' => $this->services->tableauServices($anneeId, $request->user()->faculteRestreinteId()),
            'anneeId' => $anneeId,
            'annees' => AnneeUniversitaire::orderByDesc('date_debut')->get(),
            'seuils' => SeuilService::with(['grade', 'faculte'])->orderBy('id')->get(),
            'grades' => GradeEnseignant::where('actif', true)->orderBy('ordre')->get(),
        ]);
    }

    public function seuilStore(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'grade_id' => 'nullable|exists:grades_enseignants,id',
            'faculte_id' => 'nullable|exists:facultes,id',
            'annee_universitaire_id' => 'nullable|exists:annees_universitaires,id',
            'service_min' => 'required|numeric|min:0|max:999',
            'service_cible' => 'required|numeric|min:0|max:999',
            'service_max' => 'required|numeric|min:0|max:999|gte:service_min',
        ]);

        // Isolation faculté (audit sécurité 04/10/2026) : un compte
        // restreint ne crée que dans SA faculté (jamais celle d'autrui,
        // jamais de seuil global) — cible explicite étrangère → 404.
        $restreinte = $request->user()->faculteRestreinteId();
        if ($restreinte !== null) {
            abort_unless(! isset($donnees['faculte_id']) || (int) $donnees['faculte_id'] === $restreinte, 404);
            $donnees['faculte_id'] = $restreinte;
        }

        SeuilService::create($donnees);

        return redirect()->route('admin.enseignements.services', ['annee_universitaire_id' => $donnees['annee_universitaire_id'] ?? null])
            ->with('success', 'Seuil enregistré.');
    }

    public function seuilDestroy(Request $request, SeuilService $seuil): RedirectResponse
    {
        // Isolation faculté (audit sécurité 04/10/2026) : seuil global ou
        // d'une autre faculté → hors périmètre d'un compte restreint.
        $restreinte = $request->user()->faculteRestreinteId();
        abort_unless($restreinte === null || (int) $seuil->faculte_id === $restreinte, 404);

        $seuil->delete();

        return redirect()->route('admin.enseignements.services')
            ->with('success', 'Seuil supprimé.');
    }

    public function types(): View
    {
        return view('modules.enseignement.types', [
            'types' => TypeEnseignement::orderBy('code')->get(),
        ]);
    }

    public function typeStore(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'code' => 'required|string|max:20|unique:types_enseignement,code',
            'nom' => 'required|string|max:100',
            'description' => 'nullable|string|max:1000',
        ]);

        TypeEnseignement::create($donnees + ['actif' => true]);

        return redirect()->route('admin.enseignements.types')
            ->with('success', 'Type créé.');
    }

    public function typeUpdate(Request $request, TypeEnseignement $type): RedirectResponse
    {
        $donnees = $request->validate([
            'nom' => 'required|string|max:100',
            'description' => 'nullable|string|max:1000',
            'actif' => 'sometimes|boolean',
        ]);

        $type->update($donnees + ['actif' => (bool) ($donnees['actif'] ?? false)]);

        return redirect()->route('admin.enseignements.types')
            ->with('success', 'Type mis à jour.');
    }

    public function typeDestroy(TypeEnseignement $type): RedirectResponse
    {
        if ($type->enseignements()->exists()) {
            return redirect()->back()->withErrors([
                'type' => 'Type utilisé par des enseignements.',
            ]);
        }

        $type->delete();

        return redirect()->route('admin.enseignements.types')
            ->with('success', 'Type supprimé.');
    }

    public function qualite(Request $request): View
    {
        return view('modules.enseignement.qualite', [
            'anomalies' => $this->qualite->anomalies($request->user()->faculteRestreinteId(), 200),
        ]);
    }
}
