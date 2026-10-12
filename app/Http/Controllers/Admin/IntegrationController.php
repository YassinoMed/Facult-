<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DossierAcademique;
use App\Models\Faculte;
use App\Models\ImportReport;
use App\Models\IntegrationLog;
use App\Services\Io\ExportOrchestrator;
use App\Services\Io\ImportOrchestrator;
use App\Services\Io\IoException;
use App\Services\Io\Templates\ImportTemplateDefinition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Interface web scolarité pour les intégrations : imports (deux phases
 * avec prévisualisation), exports (profils restreints), rapports et
 * journal des échanges.
 *
 * Écritures réservées à « configuration.manage » (admin_scolarite+).
 */
class IntegrationController extends Controller
{
    public function __construct(
        private readonly ImportOrchestrator $imports,
        private readonly ExportOrchestrator $exports,
    ) {}

    public function index(): View
    {
        Gate::authorize('viewAny', DossierAcademique::class);

        return view('admin.integrations.index', [
            'rapports' => ImportReport::with('user')
                ->orderByDesc('id')
                ->paginate(15, ['*'], 'rapports')
                ->withQueryString(),
            'journal' => IntegrationLog::orderByDesc('id')
                ->limit(40)
                ->get(),
        ]);
    }

    /**
     * Phase 1 import : analyse + prévisualisation — aucune écriture métier.
     */
    public function storeImport(Request $request): RedirectResponse
    {
        Gate::authorize('configuration.manage');

        $valide = $request->validate([
            'type' => 'required|in:'.implode(',', ImportTemplateDefinition::types()),
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,json,xml,xlsx'],
        ], [
            'type.in' => 'Type d\'import inconnu.',
            'file.required' => 'Veuillez sélectionner un fichier.',
            'file.max' => 'Le fichier ne doit pas dépasser 10 Mo.',
            'file.mimes' => 'Formats acceptés : CSV, JSON, XML, XLSX.',
        ]);

        try {
            $rapport = $this->imports->preparer(
                $request->file('file'),
                $valide['type'],
                $request->user()->id,
                'web',
            );
        } catch (IoException $e) {
            return redirect()->route('admin.integrations.index')
                ->with('error', $e->getMessage());
        }

        return redirect()->route('admin.integrations.preview', $rapport);
    }

    public function preview(Request $request, ImportReport $rapport): View
    {
        Gate::authorize('configuration.manage');
        abort_if($rapport->direction !== 'import', 404);
        // Audit §P3-19 : contrôle de propriété comme `erreurs()` — un agent
        // ne prévisualise que SES rapports d'import (404 silencieux sinon).
        abort_if($rapport->user_id !== $request->user()->id, 404);

        return view('admin.integrations.preview', ['rapport' => $rapport->fresh()]);
    }

    /**
     * Phase 2 import : confirmation explicite (mode atomique/partiel §39).
     */
    public function confirm(Request $request, ImportReport $rapport): RedirectResponse
    {
        Gate::authorize('configuration.manage');
        // Cohérence avec l'API et `preview` (audit §P3-19) : on ne confirme
        // que SES rapports (404 silencieux sinon).
        abort_if($rapport->user_id !== $request->user()->id, 404);

        $valide = $request->validate([
            'mode' => 'required|in:atomique,partiel',
        ], [
            'mode.in' => 'Le mode doit être atomique ou partiel.',
        ]);

        try {
            $resultat = $this->imports->confirmer($rapport, $valide['mode']);
        } catch (IoException $e) {
            return redirect()->route('admin.integrations.index')
                ->with('error', $e->getMessage());
        }

        $message = $resultat->status === 'processing'
            ? 'Import volumineux lancé — traitement en file d\'attente.'
            : "Import terminé : {$resultat->valid_rows} ligne(s) importée(s), {$resultat->duplicate_rows} ignorée(s), {$resultat->error_rows} en erreur.";

        return redirect()->route('admin.integrations.index')
            ->with('success', $message);
    }

    public function storeExport(Request $request): RedirectResponse
    {
        Gate::authorize('configuration.manage');

        $valide = $request->validate([
            'type' => 'required|in:students,grades',
            'format' => 'required|in:csv,json,xml,xlsx',
            'profil' => 'nullable|in:administratif,academique,statistique',
        ], [
            'type.in' => 'Type d\'export inconnu.',
            'format.in' => 'Format d\'export inconnu.',
            'profil.in' => 'Profil inconnu.',
        ]);

        $filtres = collect($request->only(['faculte', 'statut', 'niveau', 'annee', 'matricule', 'search']))
            ->filter()
            ->all();

        // Défense en profondeur : même si un compte restreint obtenait
        // configuration.manage, le filtre faculté est forcé à son périmètre.
        if ($request->user()->faculteRestreinteId() !== null) {
            $filtres['faculte'] = Faculte::find($request->user()->faculteRestreinteId())?->code;
        }

        try {
            $rapport = $this->exports->preparer(
                type: $valide['type'],
                format: $valide['format'],
                profil: $valide['profil'] ?? 'administratif',
                filtres: $filtres,
                userId: $request->user()->id,
                integration: 'web',
            );
        } catch (IoException $e) {
            return redirect()->route('admin.integrations.index')
                ->with('error', $e->getMessage());
        }

        return redirect()->route('admin.integrations.download', $rapport)
            ->with('success', 'Export généré : '.$rapport->total_rows.' ligne(s).');
    }

    /**
     * Téléchargement d'un export : propriétaire ou super_admin uniquement.
     */
    public function download(Request $request, ImportReport $rapport)
    {
        Gate::authorize('configuration.manage');
        abort_if($rapport->direction !== 'export', 404);
        abort_unless(
            $rapport->user_id === $request->user()->id || $request->user()->estSuperAdmin(),
            403,
        );

        return $this->exports->telecharger($rapport, $request->user());
    }
}
