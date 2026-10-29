<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInscriptionAcademiqueRequest;
use App\Http\Requests\UpdateInscriptionAcademiqueRequest;
use App\Models\DossierAcademique;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Services\ArchiveService;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class InscriptionAcademiqueController extends Controller
{
    public function __construct(
        private readonly ArchiveService $archives,
        private readonly AuditService $audit,
    ) {}

    public function store(StoreInscriptionAcademiqueRequest $request, DossierAcademique $dossier): RedirectResponse
    {
        // Créer une inscription = droit de modification sur le dossier
        // parent, scopé faculté (anti-IDOR).
        Gate::authorize('update', $dossier);

        try {
            $inscription = $this->archives->creerInscription(
                $dossier->etudiant,
                $request->validated(),
            );
        } catch (\DomainException $e) {
            // Erreur métier contrôlée (règle d'unicité annuelle,
            // périodes…) : jamais de 500, message sans donnée personnelle.
            return back()->withErrors(['annee_universitaire_id' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin.archives.show', $dossier)
            ->with('success', "Inscription {$inscription->niveau} enregistrée avec ses semestres S1 et S2.");
    }

    public function update(UpdateInscriptionAcademiqueRequest $request, InscriptionAcademique $inscription): RedirectResponse
    {
        Gate::authorize('update', $inscription);

        $ancienne = $inscription->only(['niveau', 'filiere', 'groupe', 'statut', 'observation']);
        $inscription->update($request->validated());

        $this->audit->tracer(
            $inscription->etudiant,
            HistoriqueAcademique::ACTION_MODIFICATION_INSCRIPTION,
            'inscription',
            $inscription->id,
            $ancienne,
            $request->validated(),
        );

        return redirect()->route('admin.archives.show', $inscription->etudiant->dossierAcademique)
            ->with('success', 'Inscription académique mise à jour.');
    }

    public function destroy(InscriptionAcademique $inscription): RedirectResponse
    {
        Gate::authorize('delete', $inscription);

        $etudiant = $inscription->etudiant;
        $ancienne = [
            'annee' => $inscription->anneeUniversitaire?->libelle,
            'niveau' => $inscription->niveau,
            'moyenne' => $inscription->moyenne_annuelle,
        ];

        // Transaction : suppression des semestres/notes en cascade + audit
        $inscription->delete();

        $this->audit->tracer(
            $etudiant,
            HistoriqueAcademique::ACTION_SUPPRESSION_INSCRIPTION,
            'inscription',
            $inscription->id,
            $ancienne,
        );

        return redirect()->route('admin.archives.show', $etudiant->dossierAcademique)
            ->with('success', 'Inscription académique supprimée (semestres et notes liés inclus).');
    }
}
