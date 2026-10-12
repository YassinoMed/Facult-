<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PeriodeInscriptionRequest;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\RegistrationPeriod;
use App\Modules\Referentiel\Models\Programme;
use App\Services\PeriodeInscriptionService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Périodes d'inscription (LOOP §20) : réservé à `configuration.manage`
 * (même RBAC que les facultés — aucun 2e système). L'historique normal
 * n'est jamais réécrit : l'exception est une ligne dédiée.
 */
class PeriodeInscriptionController extends Controller
{
    public function __construct(private readonly PeriodeInscriptionService $periodes) {}

    public function index(Request $request): View
    {
        $query = RegistrationPeriod::with(['anneeUniversitaire', 'faculte', 'etudiant'])
            ->orderByDesc('id');

        $filtres = $request->validate([
            'annee_universitaire_id' => 'nullable|integer|exists:annees_universitaires,id',
            'type_periode' => 'nullable|in:normale,exception',
            'statut' => 'nullable|in:active,revoquee,utilisee',
        ]);

        foreach (array_filter($filtres) as $colonne => $valeur) {
            $query->where($colonne, $valeur);
        }

        $periodes = (clone $query)->paginate(25)->withQueryString();

        // Rappels (§22) : compteurs calculés, jamais de tâche planifiée.
        $maintenant = now();
        $actives = (clone $query)->where('statut', 'active')->get();
        $resume = [
            'ouvertes' => $actives->filter(fn (RegistrationPeriod $p) => $p->estOuverte())->count(),
            'bientot' => $actives->filter(fn (RegistrationPeriod $p) => $p->estBientotFermee())->count(),
            'planifiees' => $actives->filter(fn (RegistrationPeriod $p) => $p->statutCalcule() === 'SCHEDULED')->count(),
            'exceptions' => $actives->where('type_periode', 'exception')->count(),
        ];

        return view('admin.periodes.index', [
            'periodes' => $periodes,
            'resume' => $resume,
            'filtres' => $filtres,
            'annees' => AnneeUniversitaire::orderByDesc('libelle')->get(),
            'facultes' => Faculte::where('active', true)->orderBy('nom')->get(),
            'programmes' => Programme::orderBy('code')->get(['id', 'code', 'nom']),
        ]);
    }

    public function store(PeriodeInscriptionRequest $request): RedirectResponse
    {
        $valide = $request->validated();

        try {
            if ($valide['type_periode'] === 'exception') {
                $this->periodes->creerException($valide, (int) $request->user()->id);
            } else {
                $this->periodes->creerNormale($valide, (int) $request->user()->id);
            }
        } catch (DomainException $e) {
            return back()->withErrors(['periode' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin.periodes.index')
            ->with('success', $valide['type_periode'] === 'exception'
                ? 'Ouverture exceptionnelle créée (motif enregistré, auditée).'
                : 'Période normale créée.');
    }

    /**
     * État période pour un étudiant/année (LOOP UI §14) : le frontend
     * affiche, le backend décide — la soumission reste revalidée serveur.
     */
    public function etat(Request $request): JsonResponse
    {
        $valide = $request->validate([
            'etudiant_id' => 'required|integer|exists:etudiants,id',
            'annee_universitaire_id' => 'required|integer|exists:annees_universitaires,id',
            'type_inscription' => 'nullable|in:premiere,reinscription',
        ]);

        $etudiant = Etudiant::findOrFail($valide['etudiant_id']);
        abort_unless($request->user()->peutAccederFaculte((int) $etudiant->faculte_id), 404);

        $annee = AnneeUniversitaire::findOrFail($valide['annee_universitaire_id']);

        return response()->json(
            app(PeriodeInscriptionService::class)->etat($etudiant, $annee, $valide['type_inscription'] ?? null)
        );
    }

    public function revoquer(Request $request, RegistrationPeriod $periode): RedirectResponse
    {
        $motif = $request->validate(['motif' => 'required|string|min:10|max:500'])['motif'];

        try {
            $this->periodes->revoquer($periode, (int) $request->user()->id, $motif);
        } catch (DomainException $e) {
            return back()->withErrors(['periode' => $e->getMessage()]);
        }

        return back()->with('success', "Période #{$periode->id} révoquée (reste consultable).");
    }

    public function prolonger(Request $request, RegistrationPeriod $periode): RedirectResponse
    {
        $valide = $request->validate([
            'closed_at' => 'required|date|after:today',
            'motif' => 'required|string|min:10|max:500',
        ]);

        try {
            $this->periodes->prolonger($periode, $valide['closed_at'], (int) $request->user()->id, $valide['motif']);
        } catch (DomainException $e) {
            return back()->withErrors(['periode' => $e->getMessage()]);
        }

        return back()->with('success', "Période #{$periode->id} prolongée (auditée).");
    }
}
