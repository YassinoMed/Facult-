<?php

namespace App\Modules\OnlineRequests\Controllers;

use App\Http\Controllers\Controller;
use App\Models\HistoriqueAcademique;
use App\Models\User;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\OnlineRequests\Models\RequestAttachment;
use App\Modules\OnlineRequests\Models\RequestDelegation;
use App\Modules\OnlineRequests\Requests\RejectOnlineRequestRequest;
use App\Modules\OnlineRequests\Requests\RequestPieceRequest;
use App\Modules\OnlineRequests\Services\DelegationDemandeService;
use App\Modules\OnlineRequests\Services\DemandesStatsService;
use App\Modules\OnlineRequests\Services\OnlineRequestService;
use App\Modules\OnlineRequests\Services\RequestDocumentService;
use App\Services\AuditService;
use App\Services\Interface\RecentsService;
use App\Support\Recherche;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Back-office des demandes (§6-7) : registre scopé faculté, fiche,
 * transitions serveur, pièces, PDF. Contrôleur fin.
 *
 * LOT 01 : les transitions passent l'acteur au service (permissions +
 * quatre yeux vérifiés sous verrou, en plus de la policy) et
 * l'autorité de validation se délègue (fenêtre bornée, révocable).
 */
class OnlineRequestController extends Controller
{
    public function __construct(
        private readonly OnlineRequestService $demandes,
        private readonly RequestDocumentService $documents,
        private readonly AuditService $audit,
        private readonly DelegationDemandeService $delegations,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', OnlineRequest::class);

        $query = OnlineRequest::with(['etudiant.faculte', 'type']);

        if ($faculteId = $request->user()->faculteRestreinteId()) {
            $query->where('faculte_id', $faculteId);
        } elseif ($filtreFaculte = $request->input('faculte_id')) {
            $query->where('faculte_id', $filtreFaculte);
        }

        if ($search = trim($request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->where(function ($q) use ($motif) {
                $q->where('reference', 'like', $motif)
                    ->orWhere('objet', 'like', $motif)
                    ->orWhereHas('etudiant', fn ($e) => $e
                        ->where('nom', 'like', $motif)
                        ->orWhere('prenom', 'like', $motif)
                        ->orWhere('cin', 'like', $motif)
                        ->orWhere('email', 'like', $motif)
                        ->orWhere('num_inscription', 'like', $motif));
            });
        }

        foreach (['status' => 'status', 'type' => 'request_type_id'] as $entree => $colonne) {
            if ($valeur = $request->input($entree)) {
                $query->where($colonne, $valeur);
            }
        }

        $ligne = (clone $query)->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(status IN ('submitted','received')) as nouvelles")
            ->selectRaw("SUM(status IN ('in_review','waiting_student')) as en_cours")
            ->selectRaw("SUM(status = 'approved') as approuvees")
            ->selectRaw("SUM(status = 'rejected') as rejetees")
            ->first();

        $stats = [
            'total' => (int) $ligne->total,
            'nouvelles' => (int) $ligne->nouvelles,
            'en_cours' => (int) $ligne->en_cours,
            'approuvees' => (int) $ligne->approuvees,
            'rejetees' => (int) $ligne->rejetees,
        ];

        // Analytique Phase 9 §78 : retard + délai moyen, même périmètre
        // (restriction faculté ou filtre explicite).
        $faculteStats = $request->user()->faculteRestreinteId()
            ?? ($request->input('faculte_id') !== null && $request->input('faculte_id') !== ''
                ? (int) $request->input('faculte_id') : null);
        $analytique = app(DemandesStatsService::class)
            ->statistiques($faculteStats);
        $stats['en_retard'] = $analytique['en_retard'];
        $stats['delai_moyen_jours'] = $analytique['delai_moyen_jours'];

        return view('modules.online-requests.index', [
            'demandes' => $query->latest()->paginate(12)->withQueryString(),
            'stats' => $stats,
        ]);
    }

    public function show(Request $request, OnlineRequest $demande): View
    {
        $demande->loadMissing(['etudiant.faculte', 'type', 'pieces', 'documents', 'responsable', 'anneeUniversitaire', 'messages.auteur']);

        abort_unless($request->user()->peutAccederFaculte((int) ($demande->faculte_id ?? $demande->etudiant->faculte_id)), 404);
        Gate::authorize('view', $demande);

        $this->audit->tracer($demande->etudiant, 'online_request.viewed', 'online_request', $demande->id);

        app(RecentsService::class)->enregistrer(
            (int) $request->user()->id, 'demande', (string) $demande->id,
            "{$demande->reference} — {$demande->objet}", route('admin.demandes.show', $demande)
        );

        $historique = HistoriqueAcademique::where('etudiant_id', $demande->etudiant_id)
            ->where('entite', 'online_request')
            ->where('entite_id', $demande->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('modules.online-requests.show', [
            'demande' => $demande,
            'historique' => $historique,
            'peutVoirAudit' => Gate::allows('demandes.audit'),
        ]);
    }

    public function recevoir(Request $request, OnlineRequest $demande): RedirectResponse
    {
        return $this->transition($request, $demande, 'process',
            fn () => $this->demandes->recevoir($demande, $request->user()->id, $request->user()));
    }

    public function examiner(Request $request, OnlineRequest $demande): RedirectResponse
    {
        return $this->transition($request, $demande, 'process',
            fn () => $this->demandes->examiner($demande, $request->user()));
    }

    public function assigner(Request $request, OnlineRequest $demande): RedirectResponse
    {
        $request->validate(['responsable_id' => 'required|exists:users,id']);

        return $this->transition($request, $demande, 'assign', function () use ($demande, $request) {
            $this->demandes->recevoir($demande, (int) $request->input('responsable_id'), $request->user());

            return $demande;
        });
    }

    public function demanderPiece(RequestPieceRequest $request, OnlineRequest $demande): RedirectResponse
    {
        return $this->transition($request, $demande, 'process',
            fn () => $this->demandes->demanderPiece($demande, $request->validated('commentaire'), $request->user()));
    }

    public function approuver(Request $request, OnlineRequest $demande): RedirectResponse
    {
        return $this->transition($request, $demande, 'approve', function () use ($demande, $request) {
            $demande = $this->demandes->approuver($demande, $request->user());

            // Document généré d'office si le type le prévoit (§12).
            if ($demande->type->generates_document) {
                $this->documents->genererPour($demande->fresh(['etudiant.faculte', 'type']), $request->user()->id);
            }

            return $demande;
        });
    }

    public function rejeter(RejectOnlineRequestRequest $request, OnlineRequest $demande): RedirectResponse
    {
        return $this->transition($request, $demande, 'reject',
            fn () => $this->demandes->rejeter($demande, $request->validated('motif'), $request->user()));
    }

    public function terminer(Request $request, OnlineRequest $demande): RedirectResponse
    {
        return $this->transition($request, $demande, 'process',
            fn () => $this->demandes->terminer($demande, $request->user()));
    }

    /**
     * Délégation de l'autorité de validation (LOT 01) : le titulaire
     * de `demandes.approve` accorde approbation/rejet à un collègue
     * pour une fenêtre bornée. Aucune UI dédiée pour l'instant : les
     * endpoints servent l'administration assistée et les tests.
     */
    public function deleguer(Request $request): RedirectResponse
    {
        $valides = $request->validate([
            'delegue_a' => ['required', 'integer', 'exists:users,id'],
            'valide_depuis' => ['nullable', 'date'],
            'valide_jusqua' => ['required', 'date', 'after:valide_depuis'],
            'motif' => ['required', 'string', 'max:500'],
        ]);

        $delegataire = User::findOrFail((int) $valides['delegue_a']);
        $depuis = isset($valides['valide_depuis']) ? Carbon::parse($valides['valide_depuis']) : null;

        $this->delegations->deleguer($request->user(), $delegataire, [
            'motif' => $valides['motif'],
            'valide_depuis' => $depuis,
            'valide_jusqua' => Carbon::parse($valides['valide_jusqua']),
        ]);

        return redirect()->route('admin.demandes.index')
            ->with('success', 'Délégation de validation enregistrée.');
    }

    public function revoquerDelegation(Request $request, RequestDelegation $delegation): RedirectResponse
    {
        $this->delegations->revoquer($delegation, $request->user());

        return redirect()->route('admin.demandes.index')
            ->with('success', 'Délégation révoquée.');
    }

    public function downloadPiece(Request $request, OnlineRequest $demande, RequestAttachment $piece): StreamedResponse
    {
        abort_if($piece->online_request_id !== $demande->id, 404);

        $demande->loadMissing('etudiant');
        abort_unless($request->user()->peutAccederFaculte((int) ($demande->faculte_id ?? $demande->etudiant->faculte_id)), 404);
        Gate::authorize('downloadPiece', $demande);
        abort_unless(Storage::disk('private')->exists($piece->storage_path), 404);

        $this->audit->tracer($demande->etudiant, 'online_request.piece_downloaded', 'online_request', $demande->id);

        return response()->stream(function () use ($piece) {
            $flux = Storage::disk('private')->readStream($piece->storage_path);
            abort_unless(is_resource($flux), 404);
            fpassthru($flux);
            fclose($flux);
        }, 200, [
            'Content-Type' => $piece->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'attachment; filename="piece-'.$piece->id.'.'.preg_replace('/[^a-z0-9]/', '', $piece->extension).'"',
        ]);
    }

    public function downloadDocument(Request $request, OnlineRequest $demande): StreamedResponse
    {
        $demande->loadMissing('etudiant');
        abort_unless($request->user()->peutAccederFaculte((int) ($demande->faculte_id ?? $demande->etudiant->faculte_id)), 404);
        Gate::authorize('downloadPiece', $demande);

        $document = $demande->documents()->latest()->first();
        abort_if($document === null, 404);
        abort_unless(Storage::disk('private')->exists($document->storage_path), 404);

        $this->audit->tracer($demande->etudiant, 'online_request.document_downloaded', 'online_request', $demande->id);

        return response()->stream(function () use ($document) {
            $flux = Storage::disk('private')->readStream($document->storage_path);
            abort_unless(is_resource($flux), 404);
            fpassthru($flux);
            fclose($flux);
        }, 200, [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'attachment; filename="'.$document->reference.'.pdf"',
        ]);
    }

    /**
     * @param  callable(): OnlineRequest  $action
     */
    private function transition(Request $request, OnlineRequest $demande, string $capacite, callable $action): RedirectResponse
    {
        $demande->loadMissing('etudiant');
        abort_unless($request->user()->peutAccederFaculte((int) ($demande->faculte_id ?? $demande->etudiant->faculte_id)), 404);
        Gate::authorize($capacite, $demande);

        try {
            $action();
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors());
        }

        return redirect()->route('admin.demandes.show', $demande)->with('success', 'Demande mise à jour.');
    }

    /**
     * Message au fil de discussion (Phase 9 §61) : même périmètre et
     * capacité que le traitement (`demandes.process`).
     */
    public function commenter(Request $request, OnlineRequest $demande): RedirectResponse
    {
        $demande->loadMissing('etudiant');
        abort_unless($request->user()->peutAccederFaculte((int) ($demande->faculte_id ?? $demande->etudiant->faculte_id)), 404);
        Gate::authorize('process', $demande);

        try {
            app(OnlineRequestService::class)->posterMessage(
                $demande,
                (string) $request->validate(['message' => 'required|string|max:2000'])['message'],
                (int) $request->user()->id,
                null,
            );
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors());
        }

        return redirect()->route('admin.demandes.show', $demande)->with('success', 'Message envoyé.');
    }
}
