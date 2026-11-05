<?php

namespace App\Http\Controllers\Admin\Iam;

use App\Http\Controllers\Controller;
use App\Models\Iam\AffectationIam;
use App\Models\Iam\DemandeIam;
use App\Models\Iam\ProfilIam;
use App\Models\User;
use App\Services\Iam\ServiceAffectationsIam;
use App\Services\Iam\ServiceDemandesIam;
use App\Support\Iam\CataloguePermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Gestion des affectations d'utilisateurs aux rôles (LOOP GLOBAL IAM/RBAC).
 *
 * Gardes serveur :
 *  - périmètre facultaire de l'acteur ;
 *  - interdiction d'auto-affectation ;
 *  - rôle strictement actif ;
 *  - durée bornée par `config('iam.duree_max_affectation_jours')` ;
 *  - permissions critiques => circuit à quatre yeux.
 */
class RoleAffectationController extends Controller
{
    public function index(string $identifiant, Request $request): View
    {
        $this->authorize('roles.manage');

        $profil = $this->resoudreProfil($identifiant);
        $this->verifierPerimetre($profil, $request);

        $affectations = AffectationIam::query()
            ->where('profil_id', $profil->id)
            ->with(['user', 'faculte', 'creePar', 'revoquePar'])
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        // Utilisateurs éligibles pour affectation
        $queryUtilisateurs = User::where('actif', true);
        if ($profil->faculte_id !== null) {
            $queryUtilisateurs->where('faculte_id', $profil->faculte_id);
        }

        $recherche = trim((string) $request->input('q', ''));
        if ($recherche !== '') {
            $queryUtilisateurs->where(function ($q) use ($recherche) {
                $q->where('name', 'like', "%{$recherche}%")
                    ->orWhere('email', 'like', "%{$recherche}%");
            });
        }

        $utilisateurs = $queryUtilisateurs->orderBy('name')->take(50)->get(['id', 'name', 'email', 'role', 'faculte_id']);

        return view('admin.roles.affectations', [
            'profil' => $profil,
            'affectations' => $affectations,
            'utilisateurs' => $utilisateurs,
            'recherche' => $recherche,
        ]);
    }

    public function store(
        string $identifiant,
        Request $request,
        ServiceAffectationsIam $service,
        ServiceDemandesIam $demandeService
    ): RedirectResponse {
        $this->authorize('roles.manage');

        $profil = $this->resoudreProfil($identifiant);
        $this->verifierPerimetre($profil, $request);

        $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'expire_le' => ['nullable', 'date', 'after:now'],
            'motif' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'user_id.required' => 'Le compte utilisateur cible est obligatoire.',
            'user_id.exists' => 'Utilisateur introuvable.',
            'expire_le.after' => 'La date d’expiration doit être postérieure à maintenant.',
            'motif.required' => 'Un motif d’affectation est obligatoire pour la traçabilité.',
        ]);

        if (! $profil->estActif()) {
            throw ValidationException::withMessages([
                'user_id' => "Le rôle « {$profil->nom} » doit être actif avant toute affectation (statut : {$profil->statut}).",
            ]);
        }

        $cible = User::findOrFail($request->input('user_id'));

        if ((int) $cible->id === (int) $request->user()->id) {
            throw new AuthorizationException('Vous ne pouvez pas vous affecter vous-même un rôle.');
        }

        if (! $cible->estActif()) {
            throw ValidationException::withMessages([
                'user_id' => 'Le compte bénéficiaire est inactif.',
            ]);
        }

        // Vérification de concordance facultaire
        if ($profil->faculte_id !== null && (int) $cible->faculte_id !== (int) $profil->faculte_id) {
            throw new AuthorizationException('Ce compte utilisateur n’appartient pas à la faculté de ce rôle.');
        }

        // Durée maximale institutionnelle : jamais une affectation éternelle
        // déguisée en date lointaine.
        $expireLe = $request->input('expire_le');
        if ($expireLe !== null && $expireLe !== '') {
            $maximum = now()->addDays(max(1, (int) config('iam.duree_max_affectation_jours', 365)));
            if (Carbon::parse((string) $expireLe)->greaterThan($maximum)) {
                throw ValidationException::withMessages([
                    'expire_le' => 'La durée maximale d’une affectation est de '
                        .max(1, (int) config('iam.duree_max_affectation_jours', 365)).' jours.',
                ]);
            }
        }

        // Si le rôle possède des permissions critiques et que l'acteur
        // n'est pas super_admin, l'affectation passe par quatre yeux.
        $possedeCritique = false;
        foreach ($profil->codesPermissions() as $code) {
            if (CataloguePermissions::critique($code)) {
                $possedeCritique = true;
                break;
            }
        }

        if ($possedeCritique && ! $request->user()?->estSuperAdmin()) {
            $demande = $demandeService->creerDemande(
                type: DemandeIam::TYPE_AFFECTATION_PROFIL,
                initiateur: $request->user(),
                cible: $cible,
                profil: $profil,
                faculte: $profil->faculte,
                payload: [
                    'expire_le' => $expireLe,
                    'motif' => $request->input('motif'),
                ],
                motif: (string) $request->input('motif')
            );

            return back()->with('info', "Affectation soumise pour approbation à quatre yeux (#{$demande->reference}) en raison des privilèges du rôle.");
        }

        $service->affecter(
            utilisateur: $cible,
            profil: $profil,
            faculte: $profil->faculte,
            acteur: $request->user(),
            payload: ['motif' => $request->input('motif')],
            expireLe: $expireLe
        );

        return back()->with('success', "Le rôle « {$profil->nom} » a été affecté à {$cible->name} avec succès.");
    }

    public function destroy(
        int $affectationId,
        Request $request,
        ServiceAffectationsIam $service
    ): RedirectResponse {
        $this->authorize('roles.manage');

        $affectation = AffectationIam::with(['profil', 'user'])->findOrFail($affectationId);

        $restreinte = $request->user()?->faculteRestreinteId();
        if ($restreinte !== null && $affectation->faculte_id !== null && (int) $affectation->faculte_id !== $restreinte) {
            abort(403, 'Accès non autorisé.');
        }

        $service->revoquer($affectation, $request->user(), (string) $request->input('motif', 'Révocation manuelle'));

        return back()->with('success', "L'affectation de {$affectation->user?->name} a été révoquée.");
    }

    private function resoudreProfil(string $identifiant): ProfilIam
    {
        if (is_numeric($identifiant)) {
            return ProfilIam::where('id', (int) $identifiant)->firstOrFail();
        }

        return ProfilIam::where('code', $identifiant)->firstOrFail();
    }

    private function verifierPerimetre(ProfilIam $profil, Request $request): void
    {
        $restreinte = $request->user()?->faculteRestreinteId();

        if ($restreinte !== null && (int) ($profil->faculte_id ?? 0) !== $restreinte) {
            abort(403, 'Vous ne pouvez affecter que les rôles de votre faculté.');
        }
    }
}
