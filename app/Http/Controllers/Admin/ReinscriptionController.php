<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReinscriptionRequest;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\Referentiel\Models\Programme;
use App\Services\PeriodeInscriptionService;
use App\Services\ReinscriptionService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Réinscription simplifiée : identification (route model binding) →
 * contexte prérempli (dossier, décision, niveau proposé + source) →
 * formulaire réduit → récapitulatif → confirmation transactionnelle.
 * Permissions existantes (archives.view/create) + périmètre faculté.
 */
class ReinscriptionController extends Controller
{
    public function __construct(
        private readonly ReinscriptionService $reinscriptions,
        private readonly PeriodeInscriptionService $periodes,
    ) {}

    public function creer(Request $request, Etudiant $etudiant): View
    {
        Gate::authorize('view', $etudiant);
        abort_unless($request->user()->peutAccederFaculte((int) $etudiant->faculte_id), 404);

        $annee = AnneeUniversitaire::find($request->integer('annee_universitaire_id'))
            ?? AnneeUniversitaire::active()
            ?? AnneeUniversitaire::orderByDesc('libelle')->firstOrFail();

        return view('admin.inscriptions.reinscrire', [
            'etudiant' => $etudiant,
            'contexte' => $this->reinscriptions->contexte($etudiant, $annee),
            'annees' => AnneeUniversitaire::orderByDesc('libelle')->get(),
            'etatPeriode' => $this->periodes->etat($etudiant, $annee, 'reinscription'),
        ]);
    }

    public function store(ReinscriptionRequest $request, Etudiant $etudiant): RedirectResponse
    {
        Gate::authorize('create', InscriptionAcademique::class);
        abort_unless($request->user()->peutAccederFaculte((int) $etudiant->faculte_id), 404);

        $valide = $request->validated();

        // Isolation faculté sur la CIBLE (audit sécurité 04/10/2026) :
        // jamais d'inscription écrite sur le programme d'une autre
        // faculté — refus AVANT toute règle métier (404).
        if ($valide['programme_id'] ?? null) {
            $programmeCible = Programme::findOrFail($valide['programme_id']);
            abort_unless($programmeCible->faculte_id === null
                || $request->user()->peutAccederFaculte((int) $programmeCible->faculte_id), 404);
        }

        $annee = AnneeUniversitaire::findOrFail($valide['annee_universitaire_id']);

        try {
            $inscription = $this->reinscriptions->confirmer($etudiant, $annee, [
                'programme_id' => $valide['programme_id'] ?? null,
                'niveau_id' => $valide['niveau_id'] ?? null,
                'regime' => $valide['regime'] ?? 'initiale',
                'confirmer' => true,
            ], (int) $request->user()->id);
        } catch (DomainException $e) {
            return back()->withErrors(['inscription' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin.etudiants.show', $etudiant)
            ->with('success', "Réinscription {$annee->libelle} enregistrée (inscription #{$inscription->id}).");
    }
}
