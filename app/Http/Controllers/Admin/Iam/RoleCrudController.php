<?php

namespace App\Http\Controllers\Admin\Iam;

use App\Http\Controllers\Controller;
use App\Http\Requests\Iam\StoreRoleRequest;
use App\Http\Requests\Iam\UpdateRoleRequest;
use App\Models\Faculte;
use App\Models\Iam\AffectationIam;
use App\Models\Iam\DemandeIam;
use App\Models\Iam\EvenementIam;
use App\Models\Iam\ProfilIam;
use App\Models\User;
use App\Services\Iam\ServiceDemandesIam;
use App\Services\Iam\ServiceProfilsIam;
use App\Support\Iam\CataloguePermissions;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Contrôleur CRUD des rôles et profils d'habilitation (LOOP GLOBAL IAM/RBAC).
 *
 * Gère la consultation, création, modification, activation, suspension,
 * réactivation, archivage et suppression physique des rôles personnalisés.
 * Les rôles système restent protégés contre toute altération.
 *
 * Gouvernance : un rôle personnalisé naît en `brouillon`. Un super-admin
 * peut l'activer directement ; tout autre acteur doit passer par une
 * demande à quatre yeux, sans possibilité d'auto-approbation.
 */
class RoleCrudController extends Controller
{
    public function create(Request $request): View
    {
        $this->authorize('roles.manage');

        $facultes = Faculte::where('active', true)->orderBy('nom')->get(['id', 'nom']);

        return view('admin.roles.create', [
            'facultes' => $facultes,
            'hierarchiePermissions' => CataloguePermissions::hierarchie(),
            'totalPermissions' => count(CataloguePermissions::toutes()),
        ]);
    }

    public function store(StoreRoleRequest $request, ServiceProfilsIam $service): RedirectResponse
    {
        $faculte = $request->validated('faculte_id')
            ? Faculte::find($request->validated('faculte_id'))
            : null;

        $profil = $service->creer(
            code: (string) $request->validated('code'),
            nom: (string) $request->validated('nom'),
            description: $request->validated('description'),
            faculte: $faculte,
            createur: $request->user(),
            permissionCodes: (array) $request->input('permissions', []),
            statutInitial: ProfilIam::STATUT_BROUILLON
        );

        return redirect()
            ->route('admin.roles.show', $profil->id)
            ->with('success', "Le rôle personnalisé « {$profil->nom} » ({$profil->code}) a été créé en brouillon. Activez-le pour le rendre affectable.");
    }

    public function show(string $identifiant, Request $request): View
    {
        $this->authorize('roles.view');

        // Cas 1 : Rôle système
        if (in_array($identifiant, Permissions::ROLES, true)) {
            $effectif = User::where('role', $identifiant)->count();
            $permissions = $identifiant === 'super_admin' ? Permissions::TOUTES : Permissions::pourRole($identifiant);

            return view('admin.roles.show', [
                'estSysteme' => true,
                'role' => [
                    'code' => $identifiant,
                    'nom' => ucwords(str_replace('_', ' ', $identifiant)),
                    'description' => "Rôle d'infrastructure système UniRegistre.",
                    'statut' => 'systeme',
                    'portee' => $identifiant === 'admin_faculte' ? 'Facultaire' : 'Globale',
                    'permissions' => $permissions,
                    'effectif' => $effectif,
                ],
                'permissions' => $permissions,
                'affectations' => [],
                'evenements' => [],
                'demandes' => [],
            ]);
        }

        // Cas 2 : Rôle personnalisé
        $profil = $this->resoudreProfil($identifiant);

        // Vérification de la portée facultaire pour l'acteur
        $restreinte = $request->user()?->faculteRestreinteId();
        if ($restreinte !== null && $profil->faculte_id !== null && (int) $profil->faculte_id !== $restreinte) {
            throw new AuthorizationException('Ce rôle appartient à une autre faculté et ne peut pas être consulté.');
        }

        $profil->loadMissing(['permissions', 'faculte', 'createur', 'approbateur']);

        $affectations = AffectationIam::query()
            ->where('profil_id', $profil->id)
            ->with(['user', 'faculte', 'creePar', 'revoquePar'])
            ->latest('id')
            ->get();

        $evenements = EvenementIam::query()
            ->where('objet_type', ProfilIam::class)
            ->where('objet_id', $profil->id)
            ->latest('id')
            ->take(25)
            ->get();

        $demandes = DemandeIam::query()
            ->where('profil_id', $profil->id)
            ->with(['initiateur', 'cible', 'approbations.approbateur'])
            ->latest('id')
            ->take(10)
            ->get();

        return view('admin.roles.show', [
            'estSysteme' => false,
            'profil' => $profil,
            'permissions' => $profil->codesPermissions(),
            'affectations' => $affectations,
            'evenements' => $evenements,
            'demandes' => $demandes,
        ]);
    }

    public function edit(string $identifiant, Request $request): View
    {
        $this->authorize('roles.manage');

        if (in_array($identifiant, Permissions::ROLES, true)) {
            abort(403, 'Les rôles système ne sont pas modifiables via l’interface.');
        }

        $profil = $this->resoudreProfil($identifiant);
        $this->verifierPerimetre($profil, $request->user());

        $facultes = Faculte::where('active', true)->orderBy('nom')->get(['id', 'nom']);

        return view('admin.roles.edit', [
            'profil' => $profil,
            'facultes' => $facultes,
        ]);
    }

    public function update(string $identifiant, UpdateRoleRequest $request, ServiceProfilsIam $service): RedirectResponse
    {
        $this->authorize('roles.manage');

        if (in_array($identifiant, Permissions::ROLES, true)) {
            abort(403, 'Les rôles système ne sont pas modifiables.');
        }

        $profil = $this->resoudreProfil($identifiant);
        $this->verifierPerimetre($profil, $request->user());

        // La portée facultaire ne peut être déplacée que par un super-admin.
        $faculteId = $request->has('faculte_id')
            ? $request->validated('faculte_id')
            : $profil->faculte_id;
        $changeFaculte = (int) ($faculteId ?? 0) !== (int) ($profil->faculte_id ?? 0);

        if ($changeFaculte && ! $request->user()?->estSuperAdmin()) {
            abort(403, 'Seul un super-administrateur peut modifier la portée facultaire d’un rôle.');
        }

        $faculte = $faculteId ? Faculte::find($faculteId) : null;

        $service->modifier(
            profil: $profil,
            nom: (string) $request->validated('nom'),
            description: $request->validated('description'),
            faculte: $faculte,
            modificateur: $request->user(),
            changerFaculte: $changeFaculte
        );

        return redirect()
            ->route('admin.roles.show', $profil->id)
            ->with('success', "Le rôle « {$profil->nom} » a été mis à jour.");
    }

    /**
     * Active un rôle en brouillon ou soumis.
     *
     * Super-admin : activation immédiate, tracée.
     * Autres acteurs : demande à quatre yeux ; l'approbateur ne peut être
     * ni l'initiateur, ni le créateur du rôle (sauf super-admin).
     */
    public function activer(
        string $identifiant,
        Request $request,
        ServiceProfilsIam $profils,
        ServiceDemandesIam $demandes
    ): RedirectResponse {
        $this->authorize('roles.manage');

        $profil = $this->resoudreProfil($identifiant);
        $this->verifierPerimetre($profil, $request->user());

        if ($profil->statut === ProfilIam::STATUT_ACTIF) {
            return back()->with('info', "Le rôle « {$profil->nom} » est déjà actif.");
        }

        if (! in_array($profil->statut, [ProfilIam::STATUT_BROUILLON, ProfilIam::STATUT_SOUMIS], true)) {
            throw new ConflictHttpException("Ce rôle ne peut pas être activé depuis le statut « {$profil->statut} ».");
        }

        if ($request->user()?->estSuperAdmin()) {
            $profils->valider($profil, $request->user());

            return back()->with('success', "Le rôle « {$profil->nom} » a été activé.");
        }

        $demande = $demandes->creerDemande(
            type: DemandeIam::TYPE_ACTIVATION_PROFIL,
            initiateur: $request->user(),
            cible: null,
            profil: $profil,
            faculte: $profil->faculte,
            payload: ['permissions' => $profil->codesPermissions()],
            motif: (string) $request->input('motif', "Activation du rôle {$profil->code}")
        );

        return back()->with(
            'info',
            "L’activation du rôle « {$profil->nom} » a été soumise au circuit à quatre yeux (#{$demande->reference})."
        );
    }

    public function suspendre(string $identifiant, Request $request, ServiceProfilsIam $service): RedirectResponse
    {
        $this->authorize('roles.manage');

        $profil = $this->resoudreProfil($identifiant);
        $this->verifierPerimetre($profil, $request->user());
        $motif = (string) $request->input('motif', 'Suspension administrative');

        $service->suspendre($profil, $request->user(), $motif);

        return back()->with('success', "Le rôle « {$profil->nom} » a été suspendu avec succès.");
    }

    public function reactiver(string $identifiant, Request $request, ServiceProfilsIam $service): RedirectResponse
    {
        $this->authorize('roles.manage');

        $profil = $this->resoudreProfil($identifiant);
        $this->verifierPerimetre($profil, $request->user());
        $service->reactiver($profil, $request->user());

        return back()->with('success', "Le rôle « {$profil->nom} » a été réactivé avec succès.");
    }

    public function archiver(string $identifiant, Request $request, ServiceProfilsIam $service): RedirectResponse
    {
        $this->authorize('roles.manage');

        $profil = $this->resoudreProfil($identifiant);
        $this->verifierPerimetre($profil, $request->user());
        $service->archiver($profil, $request->user());

        return back()->with('success', "Le rôle « {$profil->nom} » a été archivé définitivement.");
    }

    public function destroy(string $identifiant, Request $request, ServiceProfilsIam $service): RedirectResponse
    {
        $this->authorize('roles.manage');

        if (in_array($identifiant, Permissions::ROLES, true)) {
            abort(403, 'Un rôle système ne peut pas être supprimé.');
        }

        $profil = $this->resoudreProfil($identifiant);
        $this->verifierPerimetre($profil, $request->user());
        $nom = $profil->nom;

        $service->supprimer($profil, $request->user());

        return redirect()
            ->route('admin.roles.index')
            ->with('success', "Le rôle « {$nom} » a été supprimé.");
    }

    private function resoudreProfil(string $identifiant): ProfilIam
    {
        if (is_numeric($identifiant)) {
            return ProfilIam::where('id', (int) $identifiant)->firstOrFail();
        }

        return ProfilIam::where('code', $identifiant)->firstOrFail();
    }

    /**
     * Un acteur facultaire ne peut agir que sur les rôles de sa faculté ;
     * les rôles globaux (faculte_id NULL) lui restent inaccessibles en
     * écriture.
     */
    private function verifierPerimetre(ProfilIam $profil, ?User $acteur): void
    {
        $restreinte = $acteur?->faculteRestreinteId();

        if ($restreinte !== null && (int) ($profil->faculte_id ?? 0) !== $restreinte) {
            abort(403, 'Vous ne pouvez agir que sur les rôles de votre faculté.');
        }
    }
}
