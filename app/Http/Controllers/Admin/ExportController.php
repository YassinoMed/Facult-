<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnneeUniversitaire;
use App\Models\DossierAcademique;
use App\Models\ExportTemplate;
use App\Models\Faculte;
use App\Models\ImportReport;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\User;
use App\Services\Io\ExportOrchestrator;
use App\Services\Io\Exports\AcademicExportEngine;
use App\Services\Io\Exports\ExportColumnPolicy;
use App\Services\Io\Exports\ExportFilters;
use App\Services\Io\IoException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Interface d'export académique (§87) : type → périmètre → filtres →
 * colonnes → format → prévisualisation → génération → téléchargement.
 * Écritures réservées à « configuration.manage ».
 */
class ExportController extends Controller
{
    public function __construct(
        private readonly ExportOrchestrator $exports,
        private readonly AcademicExportEngine $engine,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', DossierAcademique::class);

        return view('admin.exports.index', [
            'modeles' => ExportTemplate::where('user_id', $request->user()->id)->orderBy('name')->get(),
            'rapports' => ImportReport::where('direction', 'export')
                ->where('user_id', $request->user()->id)
                ->orderByDesc('id')
                ->limit(10)
                ->get(),
        ]);
    }

    /**
     * §72 — listes dépendantes (faculté → filières/niveaux/groupes,
     * matières par niveau/semestre) : Alpine ne fait que de l'UX,
     * les données et la logique restent serveur.
     */
    public function options(): JsonResponse
    {
        Gate::authorize('viewAny', DossierAcademique::class);

        // Périmètre faculté (§16) : un admin_faculte ne voit que SA faculté.
        $faculteRestreinteId = request()->user()->faculteRestreinteId();

        $inscriptions = InscriptionAcademique::query()
            ->join('etudiants', 'etudiants.id', '=', 'inscriptions_academiques.etudiant_id')
            ->join('facultes', 'facultes.id', '=', 'etudiants.faculte_id')
            ->select('facultes.code as faculte_code', 'inscriptions_academiques.filiere', 'inscriptions_academiques.niveau', 'inscriptions_academiques.groupe')
            ->when($faculteRestreinteId !== null, fn ($q) => $q->where('etudiants.faculte_id', $faculteRestreinteId))
            ->distinct()
            ->get();

        $facultes = Faculte::orderBy('nom')
            ->when($faculteRestreinteId !== null, fn ($q) => $q->where('id', $faculteRestreinteId))
            ->get(['code', 'nom'])
            ->map(function ($faculte) use ($inscriptions) {
                $lignes = $inscriptions->where('faculte_code', $faculte->code);

                return [
                    'code' => $faculte->code,
                    'nom' => $faculte->nom,
                    'filieres' => $lignes->pluck('filiere')->filter()->unique()->values()->all(),
                    'niveaux' => $lignes->pluck('niveau')->unique()->values()->all(),
                    'groupes' => $lignes->pluck('groupe')->filter()->unique()->values()->all(),
                ];
            });

        return response()->json([
            'facultes' => $facultes,
            'annees' => AnneeUniversitaire::orderByDesc('libelle')->pluck('libelle'),
            'matieres' => Matiere::pourFaculte($faculteRestreinteId)
                ->with('ue.semestre.niveau')
                ->orderBy('code')->get(['code', 'nom', 'niveau', 'semestre', 'ue_id'])
                ->map(fn ($m) => ['code' => $m->code, 'nom' => $m->nom,
                    'niveau' => $m->niveauAffiche(), 'semestre' => $m->semestreRelatif()]),
            'catalogues_colonnes' => collect(ExportFilters::TYPES)
                ->mapWithKeys(fn ($t) => [$t => ExportColumnPolicy::catalogue($t)])
                ->all(),
        ]);
    }

    /**
     * §87 étape 6 — prévisualisation : compteurs + échantillon (10 lignes),
     * aucune écriture, mêmes validations que la génération.
     */
    public function preview(Request $request): JsonResponse
    {
        Gate::authorize('configuration.manage');

        $valide = $request->validate(
            array_merge(ExportFilters::regles(), [
                'type' => ['required', 'in:'.implode(',', ExportFilters::TYPES)],
                'profil' => ['nullable', 'in:administratif,academique,statistique'],
                'granularite' => ['nullable', 'in:'.implode(',', ExportFilters::GRANULARITES)],
                'columns' => ['nullable', 'array'],
                'columns.*' => ['string', 'max:30'],
            ]),
            ExportFilters::messages(),
        );

        try {
            $donnees = $this->engine->construire(
                type: $valide['type'],
                profil: $valide['profil'] ?? 'administratif',
                filtres: $this->bornerFaculte(ExportFilters::epurer((array) ($request->input('filters', []))), $request->user()),
                utilisateur: $request->user(),
                colonnes: $valide['columns'] ?? null,
                granularite: $valide['granularite'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'total' => $donnees['total'],
            'entetes' => $donnees['entetes'],
            'echantillon' => array_slice($donnees['lignes'], 0, 10),
        ]);
    }

    /**
     * §87 étapes 7-8 — confirmation + génération (sync ou job §80).
     */
    public function generate(Request $request): RedirectResponse
    {
        Gate::authorize('configuration.manage');

        $valide = $request->validate(
            array_merge(ExportFilters::regles(), [
                'type' => ['required', 'in:'.implode(',', ExportFilters::TYPES)],
                'profil' => ['nullable', 'in:administratif,academique,statistique'],
                'format' => ['required', 'in:csv,xlsx,json,xml'],
                'granularite' => ['nullable', 'in:'.implode(',', ExportFilters::GRANULARITES)],
                'columns' => ['nullable', 'array'],
                'columns.*' => ['string', 'max:30'],
            ]),
            ExportFilters::messages(),
        );

        $colonnes = array_filter((array) ($request->input('columns') ?: []));

        try {
            $rapport = $this->exports->preparer(
                type: $valide['type'],
                format: $valide['format'],
                profil: $valide['profil'] ?? 'administratif',
                filtres: $this->bornerFaculte(ExportFilters::epurer((array) ($request->input('filters', []))), $request->user()),
                userId: $request->user()->id,
                integration: 'web',
                colonnes: $colonnes ?: null,
                granularite: $valide['granularite'] ?? null,
                utilisateur: $request->user(),
            );
        } catch (IoException $e) {
            return redirect()->route('admin.exports.index')->with('error', $e->getMessage());
        }

        if ($rapport->status === 'completed') {
            return redirect()->route('admin.exports.download', $rapport)
                ->with('success', "Export généré : {$rapport->total_rows} ligne(s).");
        }

        return redirect()->route('admin.exports.index')
            ->with('success', 'Export volumineux accepté — génération en file d\'attente, consultez vos rapports.');
    }

    /**
     * Téléchargement (§81-82) : propriétaire ou super_admin, expiration vérifiée.
     */
    public function download(Request $request, ImportReport $rapport)
    {
        Gate::authorize('configuration.manage');
        abort_if($rapport->direction !== 'export', 404);
        abort_unless($rapport->user_id === $request->user()->id || $request->user()->estSuperAdmin(), 403);

        return $this->exports->telecharger($rapport, $request->user());
    }

    /* ── Modèles d'export réutilisables (§79) ── */

    public function storeTemplate(Request $request): RedirectResponse
    {
        Gate::authorize('configuration.manage');

        $valide = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'in:'.implode(',', ExportFilters::TYPES)],
            'profil' => ['nullable', 'in:administratif,academique,statistique'],
            'format' => ['required', 'in:csv,xlsx,json,xml'],
            'granularite' => ['nullable', 'in:'.implode(',', ExportFilters::GRANULARITES)],
        ], ['name.required' => 'Veuillez nommer votre modèle d\'export.']);

        ExportTemplate::updateOrCreate(
            ['user_id' => $request->user()->id, 'name' => $valide['name']],
            [
                'type' => $valide['type'],
                'profil' => $valide['profil'] ?? null,
                'format' => $valide['format'],
                'filters' => ExportFilters::epurer((array) ($request->input('filters', []))),
                'columns' => array_filter((array) ($request->input('columns') ?: [])) ?: null,
            ],
        );

        return redirect()->route('admin.exports.index')
            ->with('success', "Modèle « {$valide['name']} » enregistré — réutilisable en un clic.");
    }

    public function destroyTemplate(Request $request, ExportTemplate $template): RedirectResponse
    {
        Gate::authorize('configuration.manage');
        abort_unless($template->user_id === $request->user()->id, 403);

        $template->delete();

        return redirect()->route('admin.exports.index')->with('success', 'Modèle d\'export supprimé.');
    }

    /**
     * Défense en profondeur (§16 isolation) : quel que soit le filtre
     * demandé par le client, un admin_faculte n'exporte QUE sa faculté.
     * Le code faculté est lu en base — jamais depuis un paramètre.
     *
     * @param  array<string, mixed>  $filtres
     * @return array<string, mixed>
     */
    private function bornerFaculte(array $filtres, ?User $utilisateur): array
    {
        $faculteId = $utilisateur?->faculteRestreinteId();

        if ($faculteId !== null) {
            $filtres['faculte'] = Faculte::find($faculteId)?->code;
        }

        return $filtres;
    }
}
