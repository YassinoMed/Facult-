<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ImportReport;
use App\Models\IntegrationLog;
use App\Services\DataExchange\DataExportService;
use App\Services\DataExchange\DataImportService;
use App\Services\DataExchange\DefinitionRegistry;
use App\Services\DataExchange\ImportDependencyService;
use App\Services\Io\IoException;
use App\Services\Io\XlsxSerializer;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Centre Import/Export unifié (§27) : hub par entité, 6 étapes UX
 * (fichier → analyse → validation → preview → confirmation →
 * résultat), historique central, rapports d'erreurs.
 */
class DataExchangeController extends Controller
{
    public function __construct(
        private readonly DataImportService $imports,
        private readonly DataExportService $exports,
        private readonly ImportDependencyService $dependencies,
    ) {}

    public function hub(): View
    {
        return view('admin.donnees.hub', [
            'entites' => DefinitionRegistry::entites(),
            'exportables' => DefinitionRegistry::exportables(),
            'etats' => $this->dependencies->etatToutesEntites((int) auth()->id()),
        ]);
    }

    public function formulaire(string $entite): View
    {
        $definition = DefinitionRegistry::entites();

        abort_unless(in_array($entite, $definition, true), 404);

        return view('admin.donnees.import', [
            'entite' => $entite,
            'definition' => app(DefinitionRegistry::class)->import($entite),
        ]);
    }

    /**
     * Visualisation & vérification des données en base (Fonction œil 👁️).
     */
    public function voir(Request $request, string $entite): View
    {
        $registry = app(DefinitionRegistry::class);
        try {
            $definition = $registry->export($entite);
        } catch (InvalidArgumentException) {
            // Entité import-seul sans projection d'export (ex.
            // referentiel-mens) : rien de visualisable → 404, jamais 500.
            abort(404);
        }

        abort_unless($definition !== null, 404);

        // Entités sensibles : même garde métier que l'export (jamais
        // d'exfiltration via la visualisation).
        foreach (['factures' => 'finances.view'] as $sensible => $permission) {
            if ($entite === $sensible) {
                abort_unless($request->user()->can($permission), 403);
            }
        }

        $query = $definition->requete($request);
        $query = $definition->filtrer($query, $request);

        $total = (clone $query)->count();
        $modeles = $query->paginate(25)->withQueryString();

        $colonnes = array_values($definition->colonnes());
        $lignes = $modeles->getCollection()->map(fn ($m) => $definition->ligne($m));

        $routeMetier = match ($entite) {
            'etudiants' => \Route::has('admin.etudiants.index') ? route('admin.etudiants.index') : null,
            'enseignants' => \Route::has('admin.enseignants.index') ? route('admin.enseignants.index') : null,
            'salles' => \Route::has('admin.salles.index') ? route('admin.salles.index') : null,
            'groupes' => \Route::has('admin.groupes.index') ? route('admin.groupes.index') : null,
            'documents', 'documents-academiques' => \Route::has('admin.ged.index') ? route('admin.ged.index') : null,
            'deliberations' => \Route::has('admin.evaluation.deliberations.index') ? route('admin.evaluation.deliberations.index') : null,
            'examens' => \Route::has('admin.evaluation.examens.index') ? route('admin.evaluation.examens.index') : null,
            'factures' => \Route::has('admin.finances.factures.index') ? route('admin.finances.factures.index') : null,
            'inscriptions', 'inscriptions-academiques' => \Route::has('admin.archives.index') ? route('admin.archives.index') : null,
            default => null,
        };

        return view('admin.donnees.voir', [
            'entite' => $entite,
            'definition' => $definition,
            'colonnes' => $colonnes,
            'modeles' => $modeles,
            'lignes' => $lignes,
            'total' => $total,
            'routeMetier' => $routeMetier,
            'search' => (string) $request->input('search', ''),
        ]);
    }

    public function preview(Request $request, string $entite): View|RedirectResponse
    {
        $request->validate([
            'fichier' => 'required|file|max:10240|mimetypes:text/plain,text/csv,application/csv,application/json,application/xml,text/xml,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip',
        ]);

        try {
            $preparation = $this->imports->preparer($request->file('fichier'), $entite, (int) $request->user()->id);
        } catch (IoException $e) {
            return redirect()->back()->withErrors(['fichier' => $e->getMessage()])->withInput();
        }

        return view('admin.donnees.preview', [
            'entite' => $entite,
            'definition' => app(DefinitionRegistry::class)->import($entite),
            'token' => $preparation['token'],
            'format' => $preparation['format'],
            'resultat' => $preparation['resultat'],
            'diagnostic' => $preparation['diagnostic'] ?? null,
        ]);
    }

    public function confirmer(Request $request, string $entite): View|RedirectResponse
    {
        $request->validate([
            'token' => 'required|string|size:36',
            'mode' => 'required|in:creer,upsert,remplacer',
            'confirmation' => 'exclude_unless:mode,remplacer|required|accepted',
        ]);

        try {
            $resultat = $this->imports->confirmer(
                $request->input('token'),
                $request->input('mode'),
                (int) $request->user()->id,
            );
        } catch (IoException|DomainException $e) {
            // LOOP saisies : les exceptions métier du persister
            // (entité introuvable, hors périmètre, verrou) → 422
            // structurée, jamais un 500 brut.
            return redirect()->route('admin.donnees.import.form', $entite)
                ->withErrors(['fichier' => $e->getMessage()]);
        }

        // C2 (LOT 3) : gros volume → file d'attente (rapport + notification
        // au terme ; historique consultable immédiatement).
        if (($resultat['queued'] ?? false) === true) {
            return redirect()->route('admin.donnees.historique')
                ->with('status', 'Import volumineux mis en file : rapport et notification à la fin du traitement.');
        }

        $rapport = $resultat['rapport'];

        return view('admin.donnees.rapport', ['rapport' => $rapport, 'entite' => $entite]);
    }

    public function annuler(Request $request, string $entite): RedirectResponse
    {
        $this->imports->annuler((string) $request->input('token', ''));

        return redirect()->route('admin.donnees.hub')
            ->with('success', 'Import annulé — rien n’a été enregistré.');
    }

    /**
     * PHASE 4 — Revue semi-automatique du lot en prévisualisation
     * (accepter / modifier / rejeter / attente, décisions tracées).
     * Contrat JSON consommé par le cockpit (PHASE 8).
     */
    public function revoir(Request $request, string $entite): JsonResponse
    {
        $valide = $request->validate([
            'token' => 'required|string|size:36',
            'decisions' => 'required|array|min:1|max:5000',
            'decisions.*.ligne' => 'required|integer|min:2',
            'decisions.*.action' => 'required|in:accepter,modifier,rejeter,attente',
            'decisions.*.valeurs' => 'sometimes|array|max:200',
        ]);

        try {
            $resume = $this->imports->revoir(
                $valide['token'],
                $entite,
                $valide['decisions'],
                (int) $request->user()->id,
            );
        } catch (IoException|DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['entite' => $entite, 'resume' => $resume]);
    }

    public function exporter(Request $request, string $entite, string $format): StreamedResponse
    {
        try {
            $definition = app(DefinitionRegistry::class)->export($entite);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        abort_unless(in_array($format, $definition->formats(), true), 404);

        // Entités sensibles : permission métier requise EN PLUS de
        // data.export (jamais d'exfiltration via le centre unifié).
        foreach (['factures' => 'finances.view'] as $sensible => $permission) {
            if ($entite === $sensible) {
                abort_unless($request->user()->can($permission), 403);
            }
        }

        $export = $this->exports->exporter($request, $entite, $format);

        return response()->streamDownload(
            function () use ($export) {
                echo $export['contenu'];
            },
            $export['nom'],
            ['Content-Type' => $export['mime'], 'X-Content-Type-Options' => 'nosniff']
        );
    }

    public function modele(string $entite, string $format = 'csv'): StreamedResponse
    {
        $definition = app(DefinitionRegistry::class)->import($entite);

        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);

        $colonnes = $definition->colonnes();
        $contenu = $format === 'csv'
            ? "\xEF\xBB\xBF".implode(';', $colonnes)."\n"
            : app(XlsxSerializer::class)->serialize($colonnes, []);

        return response()->streamDownload(
            function () use ($contenu) {
                echo $contenu;
            },
            "modele-{$entite}.{$format}",
            ['Content-Type' => $format === 'csv' ? 'text/csv' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function historique(Request $request): View
    {
        // Un utilisateur à périmètre restreint ne voit que ses propres
        // rapports ; les rôles globaux conservent la vue d'ensemble.
        $restreint = $request->user()->faculteRestreinteId() !== null;

        return view('admin.donnees.historique', [
            'imports' => ImportReport::where('type', 'like', 'data.%')
                ->when($restreint, fn ($q) => $q->where('user_id', $request->user()->id))
                ->with('user')->latest()->paginate(15, ['*'], 'imports'),
            'operations' => IntegrationLog::where('integration', 'data-exchange')
                ->latest()->paginate(15, ['*'], 'operations'),
        ]);
    }

    /**
     * Rapport d'erreurs téléchargeable (§29) : row,error_code,message,
     * field,value — CSV ou JSON.
     */
    public function erreurs(Request $request, ImportReport $rapport, string $format): StreamedResponse
    {
        abort_unless(str_starts_with((string) $rapport->type, 'data.'), 404);

        // Propriété : un utilisateur à périmètre restreint ne télécharge
        // que ses propres rapports (même pattern que les imports legacy).
        if ($request->user()->faculteRestreinteId() !== null) {
            abort_if($rapport->user_id !== $request->user()->id, 404);
        }

        $erreurs = is_array($rapport->errors) ? $rapport->errors : [];

        $contenu = $format === 'csv'
            ? $this->erreursCsv($erreurs)
            : response()->json($erreurs)->getContent();

        return response()->streamDownload(
            function () use ($contenu) {
                echo $contenu;
            },
            "erreurs-{$rapport->external_reference}.{$format}",
            ['Content-Type' => $format === 'csv' ? 'text/csv' : 'application/json']
        );
    }

    /**
     * @param  array<int, array{ligne: int|string, champ: string, message: string, code: string}>  $erreurs
     */
    private function erreursCsv(array $erreurs): string
    {
        $sortie = fopen('php://temp', 'r+');
        fwrite($sortie, "\xEF\xBB\xBF");
        fputcsv($sortie, ['row', 'error_code', 'message', 'field', 'value'], ';');

        foreach ($erreurs as $erreur) {
            fputcsv($sortie, [
                $erreur['ligne'] ?? '',
                $erreur['code'] ?? '',
                $erreur['message'] ?? '',
                $erreur['champ'] ?? '',
                '',
            ], ';');
        }

        rewind($sortie);
        $contenu = (string) stream_get_contents($sortie);
        fclose($sortie);

        return $contenu;
    }
}
