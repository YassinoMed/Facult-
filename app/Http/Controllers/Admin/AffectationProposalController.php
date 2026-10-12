<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AffectationProposal;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Modules\Schedule\Models\Group;
use App\Services\Affectations\AffectationEngine;
use App\Services\Io\Exports\ExportColumnPolicy;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Contrôleur de gestion des propositions d'affectation (LOOP 7, 10 & 11).
 *
 * Espace dédié pour examiner, accepter, modifier ou rejeter les propositions
 * générées par le moteur semi-automatique.
 */
class AffectationProposalController extends Controller
{
    public function __construct(
        private readonly AffectationEngine $engine,
    ) {}

    /**
     * Cockpit des propositions en attente.
     */
    public function index(Request $request): View|JsonResponse
    {
        Gate::authorize('viewAny', Etudiant::class);

        $faculteRestreinteId = $request->user()->faculteRestreinteId();
        $anneeId = $request->input('annee_universitaire_id')
            ?: AnneeUniversitaire::where('active', true)->value('id');

        $query = AffectationProposal::with(['student.faculte', 'proposedGroup', 'anneeUniversitaire'])
            ->when($anneeId, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->when($faculteRestreinteId !== null, fn ($q) => $q->whereHas('student', fn ($sub) => $sub->where('faculte_id', $faculteRestreinteId)))
            ->when($request->filled('certitude'), fn ($q) => $q->where('certitude', $request->input('certitude')))
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->input('statut')), fn ($q) => $q->enAttente())
            ->orderByDesc('score')
            ->orderByDesc('created_at');

        $propositions = $query->paginate(30)->withQueryString();

        if ($request->wantsJson()) {
            // P0 (LOOP 2) — même règle que les vues : CIN masquée sans `exports.personal`
            // (mutation en mémoire, jamais persistée).
            if (! $request->user()->hasPermission('exports.personal')) {
                $propositions->getCollection()->each(function (AffectationProposal $proposition): void {
                    $etudiant = $proposition->relationLoaded('student') ? $proposition->student : null;
                    $cin = $etudiant?->cin;

                    if (is_string($cin) && $cin !== '') {
                        $etudiant->cin = ExportColumnPolicy::masquerCin($cin, null);
                    }
                });
            }

            return response()->json($propositions);
        }

        // Statistiques du cockpit
        $baseStats = AffectationProposal::query()
            ->when($anneeId, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->when($faculteRestreinteId !== null, fn ($q) => $q->whereHas('student', fn ($sub) => $sub->where('faculte_id', $faculteRestreinteId)));

        $enAttenteCount = (clone $baseStats)->enAttente()->count();
        $eleveeCount = (clone $baseStats)->enAttente()->where('certitude', AffectationProposal::CERTITUDE_ELEVEE)->count();
        $ambiguesCount = $enAttenteCount - $eleveeCount;
        $scoreMoyen = round((clone $baseStats)->enAttente()->avg('score') ?? 0, 1);

        $annees = AnneeUniversitaire::orderByDesc('libelle')->get();

        return view('admin.affectations.propositions', [
            'propositions' => $propositions,
            'annees' => $annees,
            'anneeId' => (int) $anneeId,
            'enAttenteCount' => $enAttenteCount,
            'eleveeCount' => $eleveeCount,
            'ambiguesCount' => $ambiguesCount,
            'scoreMoyen' => $scoreMoyen,
        ]);
    }

    /**
     * Accepte une proposition d'affectation.
     */
    public function accepter(AffectationProposal $proposal, Request $request): RedirectResponse|JsonResponse
    {
        Gate::authorize('viewAny', Etudiant::class);
        abort_unless($request->user()->peutAccederFaculte((int) $proposal->proposedGroup?->faculte_id), 404);

        try {
            $affectation = $this->engine->accepterProposition(
                $proposal,
                $request->user()->id,
                $request->input('motif', "Acceptation de la proposition #{$proposal->id}")
            );

            if ($request->wantsJson()) {
                return response()->json(['success' => true, 'affectation_id' => $affectation->id]);
            }

            return back()->with('success', "Proposition validée avec succès. L'étudiant est affecté au groupe {$proposal->proposedGroup?->code}.");
        } catch (DomainException $e) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Modifie le groupe cible d'une proposition et valide.
     */
    public function modifier(AffectationProposal $proposal, Request $request): RedirectResponse|JsonResponse
    {
        Gate::authorize('viewAny', Etudiant::class);

        $valide = $request->validate([
            'nouveau_groupe_id' => ['required', 'integer', 'exists:groups,id'],
            'motif' => ['required', 'string', 'max:255'],
        ]);

        $nouveauGroupe = Group::findOrFail($valide['nouveau_groupe_id']);
        abort_unless($request->user()->peutAccederFaculte((int) $nouveauGroupe->faculte_id), 404);

        try {
            $this->engine->modifierProposition(
                $proposal,
                (int) $valide['nouveau_groupe_id'],
                $request->user()->id,
                $valide['motif']
            );

            if ($request->wantsJson()) {
                return response()->json(['success' => true]);
            }

            return back()->with('success', "Proposition modifiée et validée sur le groupe {$nouveauGroupe->code}.");
        } catch (DomainException $e) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Rejette une proposition avec motif.
     */
    public function rejeter(AffectationProposal $proposal, Request $request): RedirectResponse|JsonResponse
    {
        Gate::authorize('viewAny', Etudiant::class);
        abort_unless($request->user()->peutAccederFaculte((int) $proposal->proposedGroup?->faculte_id), 404);

        $valide = $request->validate([
            'motif_refus' => ['required', 'string', 'max:255'],
        ]);

        $this->engine->rejeterProposition(
            $proposal,
            $request->user()->id,
            $valide['motif_refus']
        );

        if ($request->wantsJson()) {
            return response()->json(['success' => true]);
        }

        return back()->with('info', "Proposition #{$proposal->id} rejetée.");
    }

    /**
     * Validation transactionnelle en lot des propositions sélectionnées.
     */
    public function bulkAccepter(Request $request): RedirectResponse|JsonResponse
    {
        Gate::authorize('viewAny', Etudiant::class);

        $valide = $request->validate([
            'proposal_ids' => ['required_without:proposals', 'array', 'min:1', 'max:1000'],
            'proposal_ids.*' => ['integer', 'exists:affectation_proposals,id'],
            'proposals' => ['required_without:proposal_ids', 'array', 'min:1', 'max:1000'],
            'proposals.*' => ['integer', 'exists:affectation_proposals,id'],
        ]);

        $succes = 0;
        $erreurs = 0;
        $ids = $valide['proposal_ids'] ?? $valide['proposals'] ?? [];

        $propositions = AffectationProposal::whereIn('id', $ids)
            ->enAttente()
            ->with(['proposedGroup', 'student', 'anneeUniversitaire'])
            ->get();

        foreach ($propositions as $proposal) {
            if (! $request->user()->peutAccederFaculte((int) $proposal->proposedGroup?->faculte_id)) {
                $erreurs++;

                continue;
            }

            try {
                $this->engine->accepterProposition($proposal, $request->user()->id, 'Validation en masse de propositions');
                $succes++;
            } catch (DomainException) {
                $erreurs++;
            }
        }

        $message = "Traitement terminé : {$succes} proposition(s) validée(s).";
        if ($erreurs > 0) {
            $message .= " ({$erreurs} non validée(s)).";
        }

        if ($request->wantsJson()) {
            return response()->json(['succes' => $succes, 'erreurs' => $erreurs]);
        }

        return redirect()->route('admin.affectations.propositions.index')->with('success', $message);
    }
}
