<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateDocumentBatchJob;
use App\Models\DocumentBatch;
use App\Models\DocumentEvent;
use App\Models\Etudiant;
use App\Modules\StudentDossiers\Models\GeneratedDocument;
use App\Services\AuditService;
use App\Services\Documents\DocumentGenerator;
use App\Services\Documents\DocumentWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 16 §16.8 : Document Center (tableau de bord, recherche,
 * vue document + historique + chaîne supersedes, génération,
 * révocation, lots).
 */
class DocumentCenterController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('document.view');

        $query = GeneratedDocument::query()->with('dossier.etudiant')->latest('id');

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('statut')) {
            $query->where('statut', $request->string('statut'));
        }

        // Périmètre faculté : re-scopage serveur (§isolation).
        $utilisateur = $request->user();

        if ($utilisateur->faculte_id !== null && ! $utilisateur->hasPermission('configuration.manage')) {
            $query->whereHas('dossier.etudiant', fn ($q) => $q->where('faculte_id', $utilisateur->faculte_id));
        }

        return view('admin.documents-center.index', [
            'documents' => $query->paginate(25)->withQueryString(),
            'aEmettre' => (clone $query)->where('statut', 'valide')->count(),
            'enEchec' => GeneratedDocument::where('statut', 'failed')->count(),
            'revoques' => GeneratedDocument::where('statut', 'revoque')->count(),
            'expirant' => GeneratedDocument::where('statut', 'valide')
                ->whereNotNull('valide_jusquau')
                ->where('valide_jusquau', '<', now()->addDays(30))->count(),
            'aApprouver' => (clone $query)->where('statut', 'valide')->whereNull('approuve_le')->count(),
            'aEmettre' => (clone $query)->where('statut', 'valide')->whereNotNull('approuve_le')->whereNull('emis_le')->count(),
        ]);
    }

    public function show(Request $request, GeneratedDocument $document): View
    {
        Gate::authorize('document.view');
        $document->loadMissing(['dossier.etudiant', 'revoquePar', 'generePar']);
        $this->verifierPerimetre($request, $document);

        return view('admin.documents-center.show', [
            'document' => $document,
            'evenements' => DocumentEvent::where('document_id', $document->id)->latest('id')->get(),
            'chaine' => GeneratedDocument::where('remplace_id', $document->id)->get(),
            'remplace' => $document->remplace_id ? GeneratedDocument::find($document->remplace_id) : null,
        ]);
    }

    public function generer(Request $request, Etudiant $etudiant, DocumentGenerator $generateur): RedirectResponse
    {
        Gate::authorize('document.generate');

        if (! $request->user()->peutAccederFaculte((int) $etudiant->faculte_id)) {
            abort(404);
        }

        $valide = $request->validate([
            'type' => ['required', 'string', 'max:60', Rule::in($generateur->types())],
            'inscription_id' => ['required', 'integer'],
            'locale' => ['nullable', 'in:fr,ar,bilingue'],
        ]);

        try {
            $document = $generateur->generer($etudiant, $valide['type'], [
                'inscription_id' => (int) $valide['inscription_id'],
                'locale' => $valide['locale'] ?? 'fr',
            ], $request->user()->id);
        } catch (\DomainException $e) {
            return back()->withErrors(['type' => $e->getMessage()]);
        }

        return redirect()->route('admin.documents-center.show', $document);
    }

    public function approuver(Request $request, GeneratedDocument $document, DocumentWorkflowService $circuit): RedirectResponse
    {
        Gate::authorize('document.approve');
        $document->loadMissing('dossier.etudiant');
        $this->verifierPerimetre($request, $document);

        try {
            $circuit->approuver($document, (int) $request->user()->id);
        } catch (\DomainException $e) {
            return back()->withErrors(['document' => $e->getMessage()]);
        }

        return back()->with('statut', 'Document approuvé (émission possible).');
    }

    public function emettre(Request $request, GeneratedDocument $document, DocumentWorkflowService $circuit): RedirectResponse
    {
        Gate::authorize('document.issue');
        $document->loadMissing('dossier.etudiant');
        $this->verifierPerimetre($request, $document);

        try {
            $circuit->emettre($document, (int) $request->user()->id);
        } catch (\DomainException $e) {
            return back()->withErrors(['document' => $e->getMessage()]);
        }

        return back()->with('statut', 'Document émis (étudiant notifié).');
    }

    public function envoyer(Request $request, GeneratedDocument $document, DocumentWorkflowService $circuit): RedirectResponse
    {
        Gate::authorize('document.send');
        $document->loadMissing('dossier.etudiant');
        $this->verifierPerimetre($request, $document);

        try {
            $circuit->envoyer($document, (int) $request->user()->id);
        } catch (\DomainException $e) {
            return back()->withErrors(['document' => $e->getMessage()]);
        }

        return back()->with('statut', 'Document transmis à l’étudiant (notification envoyée).');
    }

    public function revoquer(Request $request, GeneratedDocument $document): RedirectResponse
    {
        Gate::authorize('document.revoke');
        $document->loadMissing('dossier.etudiant');
        $this->verifierPerimetre($request, $document);

        $valide = $request->validate(['motif' => ['required', 'string', 'min:10', 'max:500']]);

        $document->update([
            'statut' => 'revoque',
            'revoque_le' => now(),
            'revoque_par' => $request->user()->id,
            'motif_revocation' => $valide['motif'],
        ]);

        DocumentEvent::create([
            'document_id' => $document->id,
            'evenement' => 'revoque',
            'auteur_id' => $request->user()->id,
            'meta' => ['motif' => $valide['motif']],
        ]);

        return back()->with('statut', 'Document révoqué (reste vérifiable avec statut « révoqué »).');
    }

    public function telecharger(Request $request, GeneratedDocument $document): StreamedResponse
    {
        Gate::authorize('document.download');
        $document->loadMissing('dossier.etudiant');
        $this->verifierPerimetre($request, $document);

        abort_if($document->statut === 'revoque', 410, 'Document révoqué.');

        if (! Storage::disk('private')->exists($document->storage_path)) {
            abort(404, 'Fichier introuvable (alerte administrateur émise).');
        }

        // Détection à l'accès : hash discordant → 409 + alerte (§16.6/13).
        $contenu = Storage::disk('private')->get($document->storage_path);

        if (hash('sha256', (string) $contenu) !== $document->sha256) {
            report(new \RuntimeException("Hash discordant document {$document->id}"));
            abort(409, 'Intégrité compromise : régénération depuis le snapshot requise.');
        }

        DocumentEvent::create([
            'document_id' => $document->id, 'evenement' => 'telecharge', 'auteur_id' => $request->user()->id,
        ]);

        return Storage::disk('private')->download($document->storage_path, $document->numero.'.pdf');
    }

    /**
     * Impression : même fichier contrôlé que le téléchargement, servi
     * en ligne (`inline`) pour le flux d'impression du navigateur.
     * Refusé si révoqué (410, comme le portail étudiant).
     */
    public function imprimer(Request $request, GeneratedDocument $document): BinaryFileResponse
    {
        Gate::authorize('document.print');
        $document->loadMissing('dossier.etudiant');
        $this->verifierPerimetre($request, $document);

        abort_if($document->statut === 'revoque', 410, 'Document révoqué.');
        abort_unless(Storage::disk('private')->exists($document->storage_path), 404, 'Fichier introuvable.');

        $contenu = Storage::disk('private')->get($document->storage_path);

        if (hash('sha256', (string) $contenu) !== $document->sha256) {
            report(new \RuntimeException("Hash discordant document {$document->id}"));
            abort(409, 'Intégrité compromise : régénération depuis le snapshot requise.');
        }

        DocumentEvent::create([
            'document_id' => $document->id, 'evenement' => 'imprime', 'auteur_id' => $request->user()->id,
        ]);

        app(AuditService::class)->tracer(
            $document->dossier->etudiant, 'impression_document', 'document', $document->id,
            null, ['reference' => $document->reference],
        );

        return tap(
            response()->file(
                Storage::disk('private')->path($document->storage_path),
                ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff']
            ),
            fn ($reponse) => $reponse->headers->set('Content-Disposition', 'inline; filename="'.$document->numero.'.pdf"')
        );
    }

    public function lancerLot(Request $request): RedirectResponse
    {
        Gate::authorize('document_batch.run');

        $valide = $request->validate([
            'type' => ['required', 'string', 'max:60', Rule::in(app(DocumentGenerator::class)->types())],
            'etudiant_ids' => ['required', 'array', 'max:1000'],
            // LOOP saisies : pas d'IDs fantômes — chaque cible existe
            // (le lot ne gonfle pas son total avec des IDs inexistants).
            'etudiant_ids.*' => ['integer', Rule::exists('etudiants', 'id')],
        ]);

        $lot = DocumentBatch::create([
            'reference' => 'LOT-'.now()->format('Ymd-His').'-'.Str::upper(Str::random(4)),
            'type' => $valide['type'],
            'criteres' => ['source' => 'manuel'],
            'statut' => 'en_attente',
            'total' => count($valide['etudiant_ids']),
            'auteur_id' => $request->user()->id,
        ]);

        foreach (array_chunk($valide['etudiant_ids'], 200) as $paquet) {
            GenerateDocumentBatchJob::dispatch($lot->id, $paquet);
        }

        return redirect()->route('admin.documents-center.index')->with('statut', "Lot {$lot->reference} lancé.");
    }

    public function annulerLot(Request $request, DocumentBatch $lot): RedirectResponse
    {
        Gate::authorize('document_batch.cancel');

        if (! $lot->estTermine()) {
            $lot->update(['statut' => 'annule']);
        }

        return back()->with('statut', "Lot {$lot->reference} annulé.");
    }

    private function verifierPerimetre(Request $request, GeneratedDocument $document): void
    {
        $faculteId = (int) ($document->dossier?->etudiant?->faculte_id ?? 0);

        if ($faculteId !== 0 && ! $request->user()->peutAccederFaculte($faculteId)) {
            abort(404);
        }
    }
}
