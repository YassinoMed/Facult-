<?php

namespace App\Modules\Evaluation\Controllers;

use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\Evaluation\Requests\SaisieNotesAnonymesRequest;
use App\Modules\Evaluation\Requests\SaisieNotesRequest;
use App\Modules\Evaluation\Services\CompostageService;
use App\Modules\Evaluation\Services\SaisieNoteService;
use App\Services\AuditService;
use App\Services\DataExchange\DataImportService;
use App\Services\Io\IoException;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NoteEpreuveController
{
    public function saisie(Epreuve $epreuve): View
    {
        // Scope faculté → 404 avant toute Gate (convention projet).
        if ($epreuve->faculteId() !== null && request()->user()?->faculteRestreinteId() !== null
            && ! request()->user()->peutAccederFaculte($epreuve->faculteId())) {
            abort(404);
        }

        Gate::authorize('saisirNote', $epreuve);

        $epreuve->load(['matiere', 'candidats.etudiant', 'notes', 'compostages']);

        if ($epreuve->compostages->count() < $epreuve->candidats->count() && ! $epreuve->estVerrouillee()) {
            app(CompostageService::class)->genererPourEpreuve($epreuve, (int) (request()->user()?->id ?? 0));
            $epreuve->unsetRelation('compostages');
            $epreuve->load('compostages');
        }

        // Ordre aléatoire stable par numéro (§15) : aucun ordre
        // alphabétique, matricule ou inscription n'est déductible.
        $lignes = $epreuve->compostages->sortBy('numero')->values();
        $notes = $epreuve->notes->keyBy('etudiant_id');
        $statuts = $epreuve->candidats->keyBy('etudiant_id');

        return view('modules.evaluation.notes-saisie', [
            'epreuve' => $epreuve,
            'lignes' => $lignes,
            'notes' => $notes,
            'statuts' => $statuts,
        ]);
    }

    public function storeLot(SaisieNotesRequest $request, Epreuve $epreuve, SaisieNoteService $service): RedirectResponse
    {
        Gate::authorize('saisirNote', $epreuve);

        try {
            $resultat = $service->saisirLot($epreuve, $request->validated()['lignes'], (int) $request->user()->id);
        } catch (DomainException $e) {
            return back()->withErrors(['epreuve' => $e->getMessage()]);
        }

        if ($resultat['erreurs'] !== []) {
            return back()->withErrors($resultat['erreurs'])->withInput();
        }

        return back()->with('succes', "{$resultat['saisies']} note(s) enregistrée(s).");
    }

    /**
     * Saisie anonyme (§9) : `numero` résolu serveur, jamais de
     * `etudiant_id` client comme source d'autorité.
     */
    public function storeAnonyme(SaisieNotesAnonymesRequest $request, Epreuve $epreuve, SaisieNoteService $service): RedirectResponse
    {
        Gate::authorize('saisirNote', $epreuve);

        try {
            $resultat = $service->saisirLotParCompostages($epreuve, $request->validated()['lignes'], (int) $request->user()->id);
        } catch (DomainException $e) {
            return back()->withErrors(['epreuve' => $e->getMessage()]);
        }

        if ($resultat['erreurs'] !== []) {
            return back()->withErrors($resultat['erreurs'])->withInput();
        }

        return back()->with('succes', "{$resultat['saisies']} note(s) enregistrée(s).");
    }

    /**
     * Génération des numéros pour les candidats (§12) : idempotente,
     * refusée une fois l'épreuve verrouillée (§14).
     */
    public function generer(Epreuve $epreuve, CompostageService $service, Request $request): RedirectResponse
    {
        Gate::authorize('genererCompostage', $epreuve);

        try {
            $resultat = $service->genererPourEpreuve($epreuve, (int) $request->user()->id);
        } catch (DomainException $e) {
            return back()->withErrors(['epreuve' => $e->getMessage()]);
        }

        return back()->with('succes', "{$resultat['crees']} numéro(s) généré(s), {$resultat['existants']} existant(s).");
    }

    /**
     * Correspondance CMP → identité (§13) : permission dédiée, scope
     * faculté → 404, chaque résolution est auditée.
     */
    public function correspondance(Epreuve $epreuve, AuditService $audit): View
    {
        if ($epreuve->faculteId() !== null && request()->user()?->faculteRestreinteId() !== null
            && ! request()->user()->peutAccederFaculte($epreuve->faculteId())) {
            abort(404);
        }

        Gate::authorize('resoudreCompostage', $epreuve);

        $epreuve->load(['matiere', 'compostages.etudiant']);

        foreach ($epreuve->compostages as $compostage) {
            if ($compostage->etudiant !== null) {
                $audit->tracer($compostage->etudiant, 'compostage.resolved', 'epreuve', $epreuve->id, null, [
                    'numero' => $compostage->numero,
                ]);
            }
        }

        return view('modules.evaluation.compostages-correspondance', [
            'epreuve' => $epreuve,
            'compostages' => $epreuve->compostages->sortBy('numero')->values(),
        ]);
    }

    /**
     * Export enseignant (§17) : compostage + note + statut uniquement.
     */
    public function exportAnonyme(Epreuve $epreuve): StreamedResponse
    {
        Gate::authorize('saisirNote', $epreuve);

        $epreuve->load(['compostages', 'notes', 'candidats']);

        $notes = $epreuve->notes->keyBy('etudiant_id');
        $statuts = $epreuve->candidats->keyBy('etudiant_id');

        return response()->streamDownload(function () use ($epreuve, $notes, $statuts) {
            $sortie = fopen('php://output', 'w');
            fputcsv($sortie, ['compostage', 'note', 'statut']);

            foreach ($epreuve->compostages->sortBy('numero') as $compostage) {
                $note = $notes->get($compostage->etudiant_id);
                fputcsv($sortie, [
                    $compostage->numero,
                    $note?->valeur,
                    $note?->statut ?? $statuts->get($compostage->etudiant_id)?->statut,
                ]);
            }

            fclose($sortie);
        }, 'notes-anonymes-epreuve-'.$epreuve->id.'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Import anonyme (§16) : `numero,note` résolus serveur (rejet absolu de student_id).
     */
    public function importAnonyme(SaisieNotesAnonymesRequest $request, Epreuve $epreuve, SaisieNoteService $service): RedirectResponse
    {
        Gate::authorize('saisirNote', $epreuve);

        $lignes = [];

        if ($request->hasFile('fichier')) {
            $fichier = $request->file('fichier');
            $handle = fopen($fichier->getRealPath(), 'r');
            if ($handle === false) {
                return back()->withErrors(['fichier' => 'Impossible de lire le fichier.']);
            }

            $entetes = fgetcsv($handle, 1000, ',');
            if ($entetes === false || $entetes === null) {
                fclose($handle);

                return back()->withErrors(['fichier' => 'Le fichier CSV est vide.']);
            }

            // Normalisation des entêtes en minuscules
            $entetesNormalisees = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $entetes);

            // Rejet absolu de student_id / etudiant_id dans le flux anonyme (§16)
            if (in_array('student_id', $entetesNormalisees, true) || in_array('etudiant_id', $entetesNormalisees, true)) {
                fclose($handle);

                return back()->withErrors(['fichier' => 'Le flux anonyme ne doit pas contenir de colonne student_id ou etudiant_id.']);
            }

            $colCompostage = array_search('compostage', $entetesNormalisees, true);
            if ($colCompostage === false) {
                $colCompostage = array_search('numero', $entetesNormalisees, true);
            }
            $colNote = array_search('note', $entetesNormalisees, true);
            if ($colNote === false) {
                $colNote = array_search('valeur', $entetesNormalisees, true);
            }

            if ($colCompostage === false || $colNote === false) {
                fclose($handle);

                return back()->withErrors(['fichier' => 'Le fichier CSV doit contenir les colonnes compostage (ou numero) et note.']);
            }

            while (($ligne = fgetcsv($handle, 1000, ',')) !== false) {
                if (! isset($ligne[$colCompostage])) {
                    continue;
                }
                $num = trim((string) $ligne[$colCompostage]);
                $val = isset($ligne[$colNote]) && trim((string) $ligne[$colNote]) !== '' ? (float) str_replace(',', '.', trim((string) $ligne[$colNote])) : null;
                if ($num !== '') {
                    $lignes[] = [
                        'numero' => $num,
                        'valeur' => $val,
                    ];
                }
            }
            fclose($handle);
        } else {
            $lignes = $request->validated()['lignes'] ?? [];
        }

        if ($lignes === []) {
            return back()->withErrors(['fichier' => 'Aucune ligne de note à importer.']);
        }

        try {
            $resultat = $service->saisirLotParCompostages($epreuve, $lignes, (int) $request->user()->id);
        } catch (DomainException $e) {
            return back()->withErrors(['epreuve' => $e->getMessage()]);
        }

        if ($resultat['erreurs'] !== []) {
            return back()->withErrors($resultat['erreurs'])->withInput();
        }

        return back()->with('succes', "{$resultat['saisies']} note(s) importée(s).");
    }

    /**
     * Import multi-format, étape 1/2 : upload + analyse SANS écriture
     * (preview). Contexte épreuve posé depuis la route, jamais depuis
     * le fichier. Formats : CSV, XLSX, ODS, JSON, XML.
     */
    public function importMulti(Epreuve $epreuve, Request $request, DataImportService $imports): View|RedirectResponse
    {
        if ($epreuve->faculteId() !== null && request()->user()?->faculteRestreinteId() !== null
            && ! request()->user()->peutAccederFaculte($epreuve->faculteId())) {
            abort(404);
        }

        Gate::authorize('saisirNote', $epreuve);

        $request->validate([
            'fichier' => 'required|file|max:10240|mimetypes:text/plain,text/csv,application/csv,application/json,application/xml,text/xml,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.oasis.opendocument.spreadsheet,application/zip',
        ]);

        if ($epreuve->estVerrouillee()) {
            return back()->withErrors(['fichier' => 'Épreuve verrouillée : import interdit.']);
        }

        try {
            $preparation = $imports->preparer(
                $request->file('fichier'),
                'notes-epreuve-anonymes',
                (int) $request->user()->id,
                ['epreuve_id' => $epreuve->id]
            );
        } catch (IoException $e) {
            EvaluationChanged::dispatch('notes.import.failed', $epreuve->id);

            return back()->withErrors(['fichier' => $e->getMessage()])->withInput();
        }

        EvaluationChanged::dispatch('notes.import.previewed', $epreuve->id);

        return view('modules.evaluation.notes-import-preview', [
            'epreuve' => $epreuve,
            'token' => $preparation['token'],
            'format' => $preparation['format'],
            'resultat' => $preparation['resultat'],
        ]);
    }

    /**
     * Import multi-format, étape 2/2 : confirmation. Revalidation
     * anti-TOCTOU depuis le payload serveur (le navigateur ne fournit
     * que le token + le mode). Verrou posé entre-temps → refus propre,
     * rien n'est écrit.
     */
    public function importMultiConfirmer(Epreuve $epreuve, Request $request, DataImportService $imports): View|RedirectResponse
    {
        if ($epreuve->faculteId() !== null && request()->user()?->faculteRestreinteId() !== null
            && ! request()->user()->peutAccederFaculte($epreuve->faculteId())) {
            abort(404);
        }

        Gate::authorize('saisirNote', $epreuve);

        $request->validate([
            'token' => 'required|string|size:36',
            'mode' => 'required|in:creer,upsert',
        ]);

        EvaluationChanged::dispatch('notes.import.confirmed', $epreuve->id);

        try {
            $resultat = $imports->confirmer(
                $request->input('token'),
                $request->input('mode'),
                (int) $request->user()->id
            );
        } catch (IoException|DomainException $e) {
            EvaluationChanged::dispatch('notes.import.failed', $epreuve->id);

            return back()->withErrors(['fichier' => $e->getMessage()]);
        }

        if (($resultat['queued'] ?? false) === true) {
            return redirect()->route('admin.evaluations.notes.saisie', $epreuve)
                ->with('succes', 'Import volumineux mis en file : les notes seront appliquées à la fin du traitement.');
        }

        EvaluationChanged::dispatch('notes.import.completed', $epreuve->id);

        return view('modules.evaluation.notes-import-rapport', [
            'epreuve' => $epreuve,
            'rapport' => $resultat['rapport'],
        ]);
    }

    /**
     * Abandon de la preview : le token est oublié, rien n'a été écrit.
     */
    public function importMultiAnnuler(Epreuve $epreuve, Request $request, DataImportService $imports): RedirectResponse
    {
        Gate::authorize('saisirNote', $epreuve);

        $imports->annuler((string) $request->input('token', ''));

        return redirect()->route('admin.evaluations.notes.saisie', $epreuve)
            ->with('succes', 'Import annulé — rien n’a été enregistré.');
    }

    public function valider(Request $request, NoteEpreuve $note, SaisieNoteService $service): RedirectResponse
    {
        Gate::authorize('validerNote', $note);

        try {
            $service->valider($note, (int) $request->user()->id, $request->string('motif')->toString() ?: null);
        } catch (DomainException $e) {
            return back()->withErrors(['note' => $e->getMessage()]);
        }

        return back()->with('succes', 'Note validée et synchronisée.');
    }

    public function verrouiller(Request $request, NoteEpreuve $note, SaisieNoteService $service): RedirectResponse
    {
        Gate::authorize('validerNote', $note);

        $request->validate(['motif' => ['required', 'string', 'max:500']]);

        try {
            $service->verrouiller($note, (int) $request->user()->id, $request->string('motif')->toString());
        } catch (DomainException $e) {
            return back()->withErrors(['note' => $e->getMessage()]);
        }

        return back()->with('succes', 'Note verrouillée.');
    }

    public function demanderCorrection(Request $request, NoteEpreuve $note, SaisieNoteService $service): RedirectResponse
    {
        Gate::authorize('validerNote', $note);

        $request->validate(['motif' => ['required', 'string', 'max:500']]);

        try {
            $service->demanderCorrection($note, (int) $request->user()->id, $request->string('motif')->toString());
        } catch (DomainException $e) {
            return back()->withErrors(['note' => $e->getMessage()]);
        }

        return back()->with('succes', 'Demande de correction enregistrée.');
    }

    public function autoriserCorrection(Request $request, NoteEpreuve $note, SaisieNoteService $service): RedirectResponse
    {
        Gate::authorize('corriger', $note);

        $request->validate(['motif' => ['required', 'string', 'max:500']]);

        try {
            $service->autoriserCorrection($note, (int) $request->user()->id, $request->string('motif')->toString());
        } catch (DomainException $e) {
            return back()->withErrors(['note' => $e->getMessage()]);
        }

        return back()->with('succes', 'Correction autorisée : la note est à nouveau saisissable.');
    }
}
