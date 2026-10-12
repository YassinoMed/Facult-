<?php

namespace App\Modules\OnlineRequests\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\OnlineRequests\Models\RequestType;
use App\Modules\OnlineRequests\Requests\StoreOnlineRequestRequest;
use App\Modules\OnlineRequests\Requests\UploadPieceRequest;
use App\Modules\OnlineRequests\Services\OnlineRequestService;
use App\Modules\OnlineRequests\Services\RequestAttachmentService;
use App\Modules\OnlineRequests\Services\RequestDocumentService;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Espace étudiant : SES demandes uniquement (session, anti-IDOR).
 * Création, soumission, annulation, pièces, PDF de SES demandes.
 */
class MesDemandesController extends Controller
{
    public function __construct(
        private readonly OnlineRequestService $demandes,
        private readonly RequestAttachmentService $pieces,
        private readonly RequestDocumentService $documents,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): View
    {
        $etudiant = $this->etudiantConnecte($request);

        $query = OnlineRequest::with('type')
            ->where('etudiant_id', $etudiant->id)
            ->latest();

        if ($filtre = $request->input('statut')) {
            $query->where('status', $filtre);
        }

        return view('modules.online-requests.mes-demandes', [
            'etudiant' => $etudiant,
            'demandes' => $query->paginate(12)->withQueryString(),
        ]);
    }

    public function create(Request $request): View
    {
        $etudiant = $this->etudiantConnecte($request);

        return view('modules.online-requests.nouvelle', [
            'etudiant' => $etudiant,
            'types' => RequestType::where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(StoreOnlineRequestRequest $request): RedirectResponse
    {
        $etudiant = $this->etudiantConnecte($request);

        $demande = $this->demandes->creer($etudiant, $request->validated());

        return redirect()->route('etudiant.demandes.show', $demande)
            ->with('success', "Demande {$demande->reference} enregistrée en brouillon.");
    }

    public function show(Request $request, OnlineRequest $demande): View
    {
        $etudiant = $this->etudiantConnecte($request);

        abort_if($demande->etudiant_id !== $etudiant->id, 404);

        $demande->loadMissing(['type', 'pieces', 'documents', 'anneeUniversitaire', 'messages.auteur']);

        return view('modules.online-requests.ma-demande', [
            'etudiant' => $etudiant,
            'demande' => $demande,
        ]);
    }

    public function submit(Request $request, OnlineRequest $demande): RedirectResponse
    {
        $etudiant = $this->etudiantConnecte($request);

        abort_if($demande->etudiant_id !== $etudiant->id, 404);

        try {
            $this->demandes->soumettre($demande);
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors());
        }

        return redirect()->route('etudiant.demandes.show', $demande)
            ->with('success', 'Demande soumise à la scolarité.');
    }

    public function cancel(Request $request, OnlineRequest $demande): RedirectResponse
    {
        $etudiant = $this->etudiantConnecte($request);

        abort_if($demande->etudiant_id !== $etudiant->id, 404);

        try {
            $this->demandes->annuler($demande);
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors());
        }

        return redirect()->route('etudiant.demandes.index')
            ->with('success', 'Demande annulée.');
    }

    public function uploadPiece(UploadPieceRequest $request, OnlineRequest $demande): RedirectResponse
    {
        $etudiant = $this->etudiantConnecte($request);

        abort_if($demande->etudiant_id !== $etudiant->id, 404);
        abort_unless(in_array($demande->status, ['draft', 'waiting_student'], true), 403);

        try {
            $this->pieces->televerser($demande, $request->file('piece'), null);
        } catch (RuntimeException $e) {
            return redirect()->back()->withErrors(['piece' => $e->getMessage()]);
        }

        return redirect()->route('etudiant.demandes.show', $demande)
            ->with('success', 'Pièce transmise.');
    }

    /**
     * Réponse au fil de discussion (Phase 9 §61) : propriétaire
     * uniquement (404 sinon, anti-IDOR), comme les pièces.
     */
    public function repondre(Request $request, OnlineRequest $demande): RedirectResponse
    {
        $etudiant = $this->etudiantConnecte($request);

        abort_if($demande->etudiant_id !== $etudiant->id, 404);

        try {
            $this->demandes->posterMessage(
                $demande,
                (string) $request->validate(['message' => 'required|string|max:2000'])['message'],
                null,
                (int) $etudiant->id,
            );
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors());
        }

        return redirect()->route('etudiant.demandes.show', $demande)
            ->with('success', 'Réponse envoyée.');
    }

    public function downloadDocument(Request $request, OnlineRequest $demande): StreamedResponse
    {
        $etudiant = $this->etudiantConnecte($request);

        abort_if($demande->etudiant_id !== $etudiant->id, 404);

        $document = $demande->documents()->latest()->first();
        abort_if($document === null, 404);
        abort_unless(Storage::disk('private')->exists($document->storage_path), 404);

        $this->audit->tracer($etudiant, 'online_request.document_downloaded', 'online_request', $demande->id);

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

    private function etudiantConnecte(Request $request): Etudiant
    {
        return Etudiant::with('faculte')
            ->findOrFail($request->session()->get('etudiant_auth_id'));
    }
}
