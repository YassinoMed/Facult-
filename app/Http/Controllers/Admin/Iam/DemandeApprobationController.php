<?php

namespace App\Http\Controllers\Admin\Iam;

use App\Http\Controllers\Controller;
use App\Models\Iam\ApprobationIam;
use App\Models\Iam\DemandeIam;
use App\Models\User;
use App\Services\Iam\ServiceDemandesIam;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Contrôleur du circuit de validation à quatre yeux (LOOP GLOBAL IAM/RBAC).
 *
 * Toutes les listes et toutes les décisions sont filtrées par le périmètre
 * facultaire de l'acteur. L'auto-approbation et l'approbation par le
 * bénéficiaire sont refusées par le service, jamais uniquement par l'UI.
 */
class DemandeApprobationController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('roles.manage');

        $statut = (string) $request->input('statut', DemandeIam::STATUT_SOUMISE);
        $acteur = $request->user();

        $query = $this->requeteScopee($acteur)
            ->with(['initiateur', 'cible', 'profil', 'faculte', 'approbations.approbateur'])
            ->latest('id');

        if ($statut !== 'tous') {
            $query->where('statut', $statut);
        }

        $demandes = $query->paginate(20)->withQueryString();

        $base = $this->requeteScopee($acteur);
        $compteurs = [
            'en_attente' => (clone $base)->where('statut', DemandeIam::STATUT_SOUMISE)->count(),
            'approuvees' => (clone $base)->where('statut', DemandeIam::STATUT_APPROUVEE)->count(),
            'rejetees' => (clone $base)->where('statut', DemandeIam::STATUT_REJETEE)->count(),
        ];

        return view('admin.roles.demandes.index', [
            'demandes' => $demandes,
            'statut' => $statut,
            'compteurs' => $compteurs,
        ]);
    }

    public function show(int $id, Request $request): View
    {
        $this->authorize('roles.manage');

        $demande = $this->requeteScopee($request->user())
            ->with(['initiateur', 'cible', 'profil', 'faculte', 'approbations.approbateur'])
            ->findOrFail($id);

        $acteur = $request->user();

        return view('admin.roles.demandes.show', [
            'demande' => $demande,
            'peutDecider' => $this->peutDecider($demande, $acteur),
            'peutAnnuler' => $demande->estApprouvable()
                && ((int) $demande->initiateur_id === (int) $acteur->id || $acteur->estSuperAdmin()),
            'estInitiateur' => (int) $demande->initiateur_id === (int) $acteur->id,
        ]);
    }

    public function decider(
        int $id,
        Request $request,
        ServiceDemandesIam $service
    ): RedirectResponse {
        $this->authorize('roles.manage');

        $demande = $this->requeteScopee($request->user())->findOrFail($id);

        $request->validate([
            'decision' => ['required', 'in:approuve,rejete'],
            'commentaire' => ['nullable', 'string', 'max:500'],
        ]);

        $decision = (string) $request->input('decision');
        $commentaire = $request->input('commentaire');

        $service->decider($demande, $request->user(), $decision, $commentaire);

        $texte = $decision === ApprobationIam::DECISION_APPROUVE ? 'approuvée' : 'rejetée';

        return redirect()
            ->route('admin.roles.demandes.index')
            ->with('success', "La demande #{$demande->reference} a été {$texte}.");
    }

    public function annuler(
        int $id,
        Request $request,
        ServiceDemandesIam $service
    ): RedirectResponse {
        $this->authorize('roles.manage');

        $demande = $this->requeteScopee($request->user())->findOrFail($id);
        $service->annuler($demande, $request->user(), (string) $request->input('motif', 'Annulation par l’initiateur'));

        return redirect()
            ->route('admin.roles.demandes.index')
            ->with('success', "La demande #{$demande->reference} a été annulée.");
    }

    /**
     * Un acteur facultaire ne voit que les demandes de sa faculté. Un
     * acteur global voit tout.
     *
     * @return Builder<DemandeIam>
     */
    private function requeteScopee(User $acteur): Builder
    {
        $query = DemandeIam::query();
        $restreinte = $acteur->faculteRestreinteId();

        if ($restreinte !== null) {
            $query->where('faculte_id', $restreinte);
        }

        return $query;
    }

    private function peutDecider(DemandeIam $demande, User $acteur): bool
    {
        if (! $demande->estApprouvable()) {
            return false;
        }

        if ((int) $demande->initiateur_id === (int) $acteur->id) {
            return false;
        }

        if ($demande->cible_user_id !== null && (int) $demande->cible_user_id === (int) $acteur->id) {
            return false;
        }

        $restreinte = $acteur->faculteRestreinteId();

        return $restreinte === null
            || ($demande->faculte_id !== null && (int) $demande->faculte_id === $restreinte);
    }
}
