<?php

namespace App\Http\Controllers\Etudiant;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Services\EcheanceDerogationService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Consultation et suivi des dérogations / échéances par l'étudiant (Phase 4, Q141, Q142) :
 * - Périmètre de session strict (anti-IDOR) : l'étudiant connecté consulte et agit UNIQUEMENT sur son dossier.
 * - Statuts transparents et distincts : en attente, refusé, ambigu, contesté.
 * - Aucune mutation automatique de statut ni de dossier lors de la contestation (BUS-004, RECOURS-005).
 */
class DerogationController extends Controller
{
    public function __construct(
        private readonly EcheanceDerogationService $echeanceService,
    ) {}

    public function index(Request $request): View
    {
        $etudiantId = (int) $request->session()->get('etudiant_auth_id');
        $etudiant = Etudiant::with('faculte')->findOrFail($etudiantId);

        $dossier = $this->echeanceService->consulterDossierDerogations($etudiant);

        return view('etudiant.derogations.index', [
            'etudiant' => $etudiant,
            'depassements' => $dossier['depassements'],
            'demandes' => $dossier['demandes'],
            'voiesRecours' => $dossier['voies_recours'],
        ]);
    }

    /**
     * Q137 / EXC-001 : Dépôt formel d'une demande de dérogation administrative.
     * Enregistre la demande et conserve la pièce sans accorder de dérogation automatique.
     */
    public function deposer(Request $request): RedirectResponse
    {
        $etudiantId = (int) $request->session()->get('etudiant_auth_id');
        $etudiant = Etudiant::findOrFail($etudiantId);

        $donnees = $request->validate([
            'objet' => ['required', 'string', 'min:3', 'max:255'],
            'motif' => ['required', 'string', 'min:10', 'max:2000'],
            'piece_justificative' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $pieceHash = null;
        $fichier = $request->file('piece_justificative');
        $chemin = $fichier?->getRealPath();
        if (is_string($chemin) && $chemin !== '') {
            $pieceHash = hash_file('sha256', $chemin) ?: null;
        }

        try {
            $resultat = $this->echeanceService->deposerDemandeDerogation(
                $etudiant,
                $donnees['objet'],
                $donnees['motif'],
                $pieceHash
            );
        } catch (DomainException $e) {
            return redirect()
                ->route('etudiant.derogations.index')
                ->withErrors(['objet' => $e->getMessage()]);
        }

        return redirect()
            ->route('etudiant.derogations.index')
            ->with('success', "Votre demande de dérogation (référence {$resultat['demande_id']}) a été enregistrée avec succès. Elle est transmise pour instruction sans décision tacite ni délai présumé.");
    }

    /**
     * Q142 / RECOURS-005 : Enregistre une contestation d'attente prolongée.
     * Enregistre l'acte sans modifier le dossier initial ni inventer de procédure.
     */
    public function contesterAttente(Request $request): RedirectResponse
    {
        $etudiantId = (int) $request->session()->get('etudiant_auth_id');
        $etudiant = Etudiant::findOrFail($etudiantId);

        $donnees = $request->validate([
            'demande_id' => ['required', 'string', 'max:100'],
            'motif_contestation' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        try {
            $this->echeanceService->enregistrerContestationAttente(
                $etudiant,
                $donnees['demande_id'],
                $donnees['motif_contestation']
            );
        } catch (DomainException $e) {
            return redirect()
                ->route('etudiant.derogations.index')
                ->withErrors(['motif_contestation' => $e->getMessage()]);
        }

        return redirect()
            ->route('etudiant.derogations.index')
            ->with('success', 'Votre contestation de l’attente prolongée a été enregistrée avec succès. Votre dossier reste instruit selon les voies officielles.');
    }
}
