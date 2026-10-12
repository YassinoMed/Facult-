<?php

namespace App\Modules\Sentinel\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sentinel\Models\SentinelIncident;
use App\Modules\Sentinel\Models\SentinelRegle;
use App\Modules\Sentinel\Requests\TransiterIncidentRequest;
use App\Modules\Sentinel\Services\SentinelRegistry;
use App\Modules\Sentinel\Services\SentinelService;
use App\Services\Sauvegardes\BackupSchedulerService;
use App\Services\Sauvegardes\RegistreSauvegardesService;
use App\Services\SupervisionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Platform Sentinel — cockpit (incrément 1 : état + incidents ;
 * règles et transitions aux incréments suivants).
 */
class SentinelController extends Controller
{
    public function __construct(
        private readonly SentinelService $sentinel,
        private readonly RegistreSauvegardesService $sauvegardes,
        private readonly BackupSchedulerService $planning,
    ) {}

    public function index(Request $request, SupervisionService $supervision): View
    {
        Gate::authorize('viewAny', SentinelIncident::class);

        $base = SentinelIncident::query()
            ->when($request->user()->faculteRestreinteId() !== null, fn ($q) => $q->where(
                fn ($w) => $w->whereNull('faculte_id')->orWhere('faculte_id', $request->user()->faculteRestreinteId())
            ));

        $incidents = (clone $base)
            ->when($request->filled('q'), fn ($q) => $q->where(function ($w) use ($request) {
                $terme = '%'.$request->input('q').'%';
                $w->where('titre', 'like', $terme)
                    ->orWhere('code', 'like', $terme)
                    ->orWhere('module', 'like', $terme)
                    ->orWhere('diagnostic', 'like', $terme);
            }))
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->input('statut')))
            ->when($request->filled('gravite'), fn ($q) => $q->where('gravite', $request->input('gravite')))
            ->when($request->filled('categorie'), fn ($q) => $q->where('categorie', $request->input('categorie')))
            ->when($request->filled('module'), fn ($q) => $q->where('module', $request->input('module')))
            ->orderByDesc('derniere_detection_le')
            ->paginate(15)
            ->withQueryString();

        $modules = (clone $base)->distinct()->whereNotNull('module')->pluck('module')->sort()->values();

        return view('modules.sentinel.index', [
            'incidents' => $incidents,
            'sondes' => $supervision->sondes(),
            'modules' => $modules,
            'derniereExecution' => (clone $base)->max('derniere_detection_le') ?? Cache::get('sentinel:derniere-execution'),
            'ouverts' => (clone $base)->where('statut', SentinelIncident::STATUT_OUVERT)->count(),
            'acquittes' => (clone $base)->where('statut', SentinelIncident::STATUT_ACQUITTE)->count(),
            'resolus' => (clone $base)->where('statut', SentinelIncident::STATUT_RESOLU)->count(),
            'critiques' => (clone $base)->actifs()->where('gravite', 'critique')->count(),
            'total' => (clone $base)->count(),
            // Sauvegardes : synthèse temps réel (détail : admin.system.backups.index).
            'sauvegarde' => [
                'dernier_succes' => $this->sauvegardes->dernierReussi(),
                'prochaine' => $this->planning->prochaineExecution(),
                'statistiques' => $this->sauvegardes->statistiques(),
            ],
        ]);
    }

    /**
     * Transition de cycle de vie (garde TRANSITIONS + scope faculté).
     */
    public function transiter(TransiterIncidentRequest $request, SentinelIncident $incident): RedirectResponse
    {
        Gate::authorize('manage', $incident);

        $cible = $request->validated()['cible'];

        DB::transaction(function () use ($incident, $cible, $request) {
            $incidentVerrouille = SentinelIncident::lockForUpdate()->find($incident->id);
            abort_unless($incidentVerrouille !== null && $incidentVerrouille->peutTransiterVers($cible), 422, 'Transition interdite.');

            $incidentVerrouille->update(array_merge(
                ['statut' => $cible],
                $cible === SentinelIncident::STATUT_RESOLU ? ['resolu_le' => now()] : [],
            ));

            Log::info('sentinel.incident_statut', [
                'incident' => $incidentVerrouille->id, 'code' => $incidentVerrouille->code,
                'cible' => $cible, 'par' => $request->user()->id,
            ]);
        });

        return back()
            ->with('success', "Incident {$incident->code} marqué comme {$cible}.");
    }

    /**
     * Détail technique (contexte sans PII : titres, codes, dates, liens).
     */
    public function show(Request $request, SentinelIncident $incident): View
    {
        Gate::authorize('view', $incident);

        return view('modules.sentinel.show', ['incident' => $incident]);
    }

    /**
     * Règles enregistrées + surcharges (activation/gravité).
     */
    public function regles(): View
    {
        Gate::authorize('viewAny', SentinelIncident::class);

        $surcharges = SentinelRegle::all()->keyBy('code');

        return view('modules.sentinel.regles', [
            'controles' => app(SentinelRegistry::class)->controles(),
            'surcharges' => $surcharges,
        ]);
    }

    /**
     * Bascule actif/inactif d'un contrôle (surcharge persistée).
     */
    public function basculer(Request $request, string $code): RedirectResponse
    {
        abort_unless($request->user()->can('sentinel.manage'), 403);

        $controle = collect(app(SentinelRegistry::class)->controles())
            ->firstWhere(fn ($c) => $c->code() === $code);

        abort_unless($controle !== null, 404);

        $regle = SentinelRegle::firstOrCreate(
            ['code' => $controle->code()],
            ['categorie' => $controle->categorie(), 'gravite' => $controle->gravite(), 'module' => $controle->module()]
        );

        // firstOrCreate ne recharge pas les défauts DB (actif=true) dans
        // l'instance créée : relire avant de basculer, sinon `! null`.
        $regle = $regle->fresh();
        $regle->update(['actif' => ! $regle->actif]);

        return redirect()->route('admin.sentinel.regles')
            ->with('success', "Contrôle {$code} ".($regle->actif ? 'activé.' : 'désactivé.'));
    }

    /**
     * Ronde manuelle (même moteur que la commande planifiée).
     */
    public function executer(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('sentinel.manage'), 403);

        $resultat = $this->sentinel->executer();

        return redirect()->route('admin.sentinel.index')
            ->with('success', "Ronde exécutée : {$resultat['executes']} contrôle(s), {$resultat['incidents']} incident(s).");
    }
}
