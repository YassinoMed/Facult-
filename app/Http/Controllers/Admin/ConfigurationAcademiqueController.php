<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAnneeUniversitaireRequest;
use App\Http\Requests\StoreMatiereRequest;
use App\Http\Requests\UpdateAnneeUniversitaireRequest;
use App\Http\Requests\UpdateMatiereRequest;
use App\Models\AnneeUniversitaire;
use App\Models\DossierAcademique;
use App\Models\InscriptionPedagogique;
use App\Models\Matiere;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Referentiel\Models\MaquetteUeEcue;
use App\Modules\Schedule\Models\ScheduleSlot;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Configuration académique : années universitaires + catalogue des matières.
 * La consultation requiert « archives.view », les écritures « configuration.manage ».
 */
class ConfigurationAcademiqueController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', DossierAcademique::class);

        return view('admin.archives.configuration', [
            'annees' => AnneeUniversitaire::withCount('inscriptions')->orderByDesc('libelle')->get(),
            'matieres' => Matiere::withCount('notes')->orderBy('niveau')->orderBy('semestre')->orderBy('code')->paginate(15),
        ]);
    }

    /* ── Années universitaires ── */

    public function storeAnnee(StoreAnneeUniversitaireRequest $request): RedirectResponse
    {
        AnneeUniversitaire::create($request->validated());

        return redirect()->route('admin.archives.configuration.index')
            ->with('success', 'Année universitaire créée avec succès.');
    }

    public function updateAnnee(UpdateAnneeUniversitaireRequest $request, AnneeUniversitaire $anneeUniversitaire): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $anneeUniversitaire) {
                $donnees = $request->validated();

                // Une seule année active à la fois : les autres sont
                // désactivées AVANT soi (la garde unique `active_unique`
                // refuserait l'inverse) ; garde DB en renfort.
                if (! empty($donnees['active'])) {
                    AnneeUniversitaire::query()->lockForUpdate()->get();
                    AnneeUniversitaire::whereKeyNot($anneeUniversitaire->id)
                        ->update(['active' => false, 'active_unique' => null]);
                }

                $anneeUniversitaire->update($donnees);
            });
        } catch (QueryException) {
            return redirect()->back()->withErrors([
                'active' => 'Activation impossible : une autre année vient d’être activée simultanément.',
            ])->withInput();
        }

        return redirect()->route('admin.archives.configuration.index')
            ->with('success', 'Année universitaire mise à jour.');
    }

    public function destroyAnnee(AnneeUniversitaire $anneeUniversitaire): RedirectResponse
    {
        if ($anneeUniversitaire->inscriptions()->exists()) {
            return redirect()->route('admin.archives.configuration.index')
                ->with('error', 'Impossible de supprimer : des inscriptions académiques y sont rattachées.');
        }

        $anneeUniversitaire->delete();

        return redirect()->route('admin.archives.configuration.index')
            ->with('success', 'Année universitaire supprimée.');
    }

    /* ── Catalogue des matières ── */

    public function storeMatiere(StoreMatiereRequest $request): RedirectResponse
    {
        Matiere::create($request->validated());

        return redirect()->route('admin.archives.configuration.index')
            ->with('success', 'Matière ajoutée au catalogue.');
    }

    public function updateMatiere(UpdateMatiereRequest $request, Matiere $matiere): RedirectResponse
    {
        $matiere->update($request->validated());

        return redirect()->route('admin.archives.configuration.index')
            ->with('success', 'Matière mise à jour.');
    }

    public function destroyMatiere(Matiere $matiere): RedirectResponse
    {
        // Politique de suppression (LOOP référentiel §18) : une matière
        // référencée par l'historique ou l'exploitation n'est jamais
        // supprimée physiquement (archiver via `actif = false` à la place).
        $blocage = $this->blocageSuppressionMatiere($matiere);

        if ($blocage !== null) {
            return redirect()->route('admin.archives.configuration.index')
                ->with('error', 'Impossible de supprimer : '.$blocage);
        }

        $matiere->delete();

        return redirect()->route('admin.archives.configuration.index')
            ->with('success', 'Matière supprimée du catalogue.');
    }

    /**
     * Motif bloquant ou null si la matière n'est référencée nulle part.
     */
    private function blocageSuppressionMatiere(Matiere $matiere): ?string
    {
        if ($matiere->notes()->exists()) {
            return 'des notes sont rattachées à cette matière.';
        }

        if (Epreuve::where('matiere_id', $matiere->id)->exists()) {
            return 'des épreuves utilisent cette matière.';
        }

        if (ScheduleSlot::where('matiere_id', $matiere->id)->exists()) {
            return 'des créneaux d’emploi du temps utilisent cette matière.';
        }

        if ($matiere->enseignements()->exists()) {
            return 'des enseignements utilisent cette matière.';
        }

        if (InscriptionPedagogique::where('matiere_id', $matiere->id)->exists()) {
            return 'des inscriptions pédagogiques utilisent cette matière.';
        }

        if (MaquetteUeEcue::where('matiere_id', $matiere->id)->exists()) {
            return 'des maquettes référencent cette matière.';
        }

        return null;
    }
}
