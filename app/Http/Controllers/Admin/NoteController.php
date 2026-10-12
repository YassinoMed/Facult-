<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNoteRequest;
use App\Http\Requests\UpdateNoteRequest;
use App\Models\InscriptionAcademique;
use App\Models\Note;
use App\Services\ArchiveService;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class NoteController extends Controller
{
    public function __construct(
        private readonly ArchiveService $archives,
        private readonly AuditService $audit,
    ) {}

    public function store(StoreNoteRequest $request, InscriptionAcademique $inscription): RedirectResponse
    {
        // Créer une note = droit de modification sur l'inscription parente,
        // scopé faculté (anti-IDOR : {inscription} manipulable dans l'URL).
        Gate::authorize('update', $inscription);

        $note = $this->archives->saisirNote($inscription, $request->validated());

        return redirect()->route('admin.archives.show', $inscription->etudiant->dossierAcademique)
            ->with('success', "Note enregistrée pour {$note->matiere->code} — moyennes recalculées.");
    }

    public function update(UpdateNoteRequest $request, Note $note): RedirectResponse
    {
        Gate::authorize('update', $note);

        $this->archives->modifierNote($note, $request->validated());

        return redirect()->route('admin.archives.show', $note->etudiant->dossierAcademique)
            ->with('success', 'Note corrigée — moyennes recalculées et action journalisée.');
    }

    /**
     * Verrouille ou déverrouille une note (rôles supérieurs uniquement).
     */
    public function toggleLock(Note $note): RedirectResponse
    {
        Gate::authorize('lock', $note);

        $verrouiller = ! $note->estVerrouillee();
        $this->archives->basculerVerrou($note, $verrouiller);

        return redirect()->route('admin.archives.show', $note->etudiant->dossierAcademique)
            ->with('success', $verrouiller
                ? 'Note verrouillée : elle n\'est plus modifiable par les agents.'
                : 'Note déverrouillée.');
    }

    public function destroy(Note $note): RedirectResponse
    {
        Gate::authorize('delete', $note);

        $this->archives->supprimerNote($note);

        return redirect()->route('admin.archives.show', $note->etudiant->dossierAcademique)
            ->with('success', 'Note supprimée — moyennes recalculées.');
    }
}
