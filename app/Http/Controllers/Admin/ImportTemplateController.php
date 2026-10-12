<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DossierAcademique;
use App\Models\ImportReport;
use App\Services\Io\Templates\ImportTemplateDefinition;
use App\Services\Io\Templates\ImportTemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Modèles d'importation (§91-101) : catalogue filtré par permission (§102),
 * téléchargement XLSX/CSV/JSON (§111), export du rapport d'erreurs (§109).
 */
class ImportTemplateController extends Controller
{
    public function __construct(private readonly ImportTemplateService $modeles) {}

    /**
     * §101 — section « Modèles d'importation » : cartes par type.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', DossierAcademique::class);

        $types = [];
        foreach (ImportTemplateDefinition::definitions() as $type => $definition) {
            if ($request->user()->hasPermission($definition['permissionWeb'])) {
                $types[$type] = $definition + ['version' => ImportTemplateDefinition::VERSIONS[$type]];
            }
        }

        // Réponses JSON (onglet Alpine de la page Intégrations)
        if ($request->wantsJson()) {
            return response()->json([
                'templates' => collect($types)->map(fn ($d, $t) => [
                    'type' => $t, 'label' => $d['label'], 'icone' => $d['icone'],
                    'description' => $d['description'], 'formats' => $d['formats'], 'version' => $d['version'],
                ])->values(),
            ]);
        }

        return view('admin.integrations.modeles', ['types' => $types]);
    }

    /**
     * Téléchargement d'un modèle — permission vérifiée par type (§102).
     */
    public function download(Request $request, string $type, string $format)
    {
        $definition = ImportTemplateDefinition::definition($type);
        abort_if($definition === null, 404);

        Gate::authorize($definition['permissionWeb']);

        abort_unless(in_array($format, $definition['formats'], true), 404, 'Format non disponible pour ce modèle.');

        $avecExemple = ! $request->boolean('vide'); // §93/§94 : deux modes

        $fichier = $this->modeles->generer($type, $format, $avecExemple);

        // §110 : génération serveur uniquement, jamais d'URL externe
        return response()->streamDownload(
            fn () => print ($fichier['contenu']),
            $fichier['nom'],
            [
                'Content-Type' => $fichier['mime'],
                'Cache-Control' => 'no-store',
            ],
        );
    }

    /**
     * §109 — téléchargement du rapport d'erreurs d'un import.
     */
    public function erreurs(Request $request, ImportReport $rapport)
    {
        Gate::authorize('configuration.manage');
        abort_if($rapport->direction !== 'import' || $rapport->user_id !== $request->user()->id, 404);

        $format = $request->query('format', 'xlsx');

        $fichier = $this->modeles->exporterErreurs($rapport, in_array($format, ['csv', 'xlsx'], true) ? $format : 'xlsx');

        return response()->streamDownload(
            fn () => print ($fichier['contenu']),
            $fichier['nom'],
            ['Content-Type' => $fichier['mime']],
        );
    }
}
