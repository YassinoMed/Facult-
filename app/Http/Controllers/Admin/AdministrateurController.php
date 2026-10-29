<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttribuerRoleRequest;
use App\Http\Requests\StoreAdministrateurRequest;
use App\Http\Requests\UpdateAdministrateurRequest;
use App\Models\Faculte;
use App\Models\User;
use App\Services\AttributionRoleService;
use App\Services\VerrouillageService;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Gestion des administrateurs du back-office — SUPER ADMIN uniquement
 * (§15, middleware `super_admin` sur les routes).
 *
 * Un admin_faculte est TOUJOURS rattaché à une faculté (contrainte
 * métier) : le rôle et la faculté sont fixés CÔTÉ SERVEUR lors de la
 * création, jamais choisis par le client pour un compte existant.
 * Le mot de passe est haché (cast `hashed`) et jamais journalisé.
 *
 * Audit (§18) : les événements admin.created / admin.permission_changed
 * sont tracés dans le journal applicatif (acteur, rôle, action, IP) —
 * AUCUN secret (mot de passe, hash, token) n'y figure jamais.
 */
class AdministrateurController extends Controller
{
    public function index(Request $request, AttributionRoleService $attribution): View
    {
        $administrateurs = User::with('faculte')
            ->when($recherche = trim((string) $request->string('search')), function ($query) use ($recherche) {
                $motif = '%'.str_replace(['%', '_', '\\'], '', $recherche).'%';
                $query->where(fn ($q) => $q->where('name', 'like', $motif)->orWhere('email', 'like', $motif));
            })
            ->when($request->filled('role'), fn ($query) => $query->where('role', $request->string('role')))
            ->when($request->filled('faculte_id'), fn ($query) => $query->where('faculte_id', $request->integer('faculte_id')))
            ->when($request->filled('actif'), fn ($query) => $query->where('actif', $request->boolean('actif')))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.administrateurs.index', [
            'administrateurs' => $administrateurs,
            'facultes' => Faculte::orderBy('nom')->get(),
            'rolesAttribuables' => $attribution->rolesAttribuables($request->user()),
            'registrePermissions' => $this->registrePermissions(),
        ]);
    }

    /** @return array<string, array<int, string>> */
    private function registrePermissions(): array
    {
        $registre = [];
        foreach (Permissions::ROLES as $role) {
            $registre[$role] = $role === 'super_admin' ? Permissions::TOUTES : Permissions::pourRole($role);
        }

        return $registre;
    }

    public function create(): View
    {
        return view('admin.administrateurs.create', [
            'facultes' => Faculte::where('active', true)->orderBy('nom')->get(),
        ]);
    }

    /**
     * Création d'un compte back-office (transaction atomique) :
     * - rôle dans la liste RBAC valide (FormRequest) ;
     * - admin_faculte ⇒ faculte_id obligatoire ;
     * - email unique ; mot de passe haché, jamais loggé.
     */
    public function store(StoreAdministrateurRequest $request): RedirectResponse
    {
        $utilisateur = DB::transaction(function () use ($request): User {
            // `role` n'est plus mass-assignable (audit §P3-17) : attribué
            // via la méthode validée, jamais depuis l'entrée HTTP brute.
            $role = $request->validated('role');
            $utilisateur = User::creerCompteBackOffice([
                'name' => $request->validated('name'),
                'email' => strtolower($request->validated('email')),
                'password' => $request->validated('password'),
                'faculte_id' => $role === 'admin_faculte'
                    ? (int) $request->validated('faculte_id')
                    : null,
                'actif' => true,
            ], $role);

            // §18 — audit applicatif : aucune donnée sensible
            Log::channel(config('logging.default'))->notice('admin.created', [
                'acteur_id' => $request->user()->id,
                'acteur_role' => $request->user()->role,
                'administre_id' => $utilisateur->id,
                'administre_role' => $utilisateur->role,
                'faculte_id' => $utilisateur->faculte_id,
                'adresse_ip' => $request->ip(),
            ]);

            return $utilisateur;
        });

        return redirect()->route('admin.administrateurs.index')
            ->with('success', "Le compte « {$utilisateur->name} » ({$utilisateur->role_libelle}) a été créé.");
    }

    /**
     * Activation / désactivation d'un compte (le super admin ne peut
     * pas se désactiver lui-même — anti-verrouillage du back-office).
     */
    public function toggleStatut(Request $request, User $administrateur): RedirectResponse
    {
        abort_if($administrateur->id === $request->user()->id, 403, 'Vous ne pouvez pas désactiver votre propre compte.');

        // Garde-fou : jamais de verrouillage du back-office — au moins un
        // super-admin actif doit subsister.
        if ($administrateur->estSuperAdmin() && $administrateur->actif) {
            $autresActifs = User::where('role', 'super_admin')
                ->where('actif', true)
                ->where('id', '!=', $administrateur->id)
                ->count();

            abort_if($autresActifs === 0, 403, 'Impossible : au moins un super-admin actif doit subsister.');
        }

        $nouvelEtat = ! $administrateur->actif;
        $administrateur->update(['actif' => $nouvelEtat]);

        // AUTH-01 (correctif 13/10) : une suspension révoque aussi les
        // sessions déjà émises du compte — un cookie actif ne survit pas
        // à la désactivation (double barrière avec EnsureBackOffice).
        // LOOP N : suspension = purge du PIN secondaire (plus de reprise).
        if (! $nouvelEtat) {
            DB::table('sessions')->where('user_id', $administrateur->id)->delete();
            app(VerrouillageService::class)->purger($administrateur, 'suspension');
        }

        // §18 — audit applicatif : aucune donnée sensible
        Log::channel(config('logging.default'))->notice('admin.permission_changed', [
            'acteur_id' => $request->user()->id,
            'acteur_role' => $request->user()->role,
            'administre_id' => $administrateur->id,
            'administre_role' => $administrateur->role,
            'ancien' => ['actif' => ! $administrateur->actif],
            'nouveau' => ['actif' => $administrateur->actif],
            'adresse_ip' => $request->ip(),
        ]);

        return redirect()->route('admin.administrateurs.index')
            ->with('success', "Le compte « {$administrateur->name} » a été ".($administrateur->actif ? 'réactivé' : 'désactivé').'.');
    }

    public function edit(User $administrateur): View
    {
        return view('admin.administrateurs.edit', [
            'administrateur' => $administrateur->load('faculte'),
            'facultes' => Faculte::where('active', true)->orderBy('nom')->get(),
        ]);
    }

    /**
     * Modification : nom, rôle (via definirRole validé), rattachement
     * faculté. Interdits : modifier son propre rôle, rétrograder le
     * dernier super-admin actif, rattacher un non-admin_faculte.
     */
    public function update(UpdateAdministrateurRequest $request, User $administrateur): RedirectResponse
    {
        abort_if($administrateur->id === $request->user()->id && $request->validated('role') !== $administrateur->role, 403, 'Vous ne pouvez pas modifier votre propre rôle.');

        $ancien = ['role' => $administrateur->role, 'faculte_id' => $administrateur->faculte_id, 'name' => $administrateur->name];
        $role = $request->validated('role');

        if ($administrateur->estSuperAdmin() && $role !== 'super_admin' && $administrateur->actif) {
            $autresActifs = User::where('role', 'super_admin')->where('actif', true)->where('id', '!=', $administrateur->id)->count();
            abort_if($autresActifs === 0, 403, 'Impossible : au moins un super-admin actif doit subsister.');
        }

        DB::transaction(function () use ($request, $administrateur, $role) {
            $administrateur->update([
                'name' => $request->validated('name'),
                'faculte_id' => $role === 'admin_faculte' ? (int) $request->validated('faculte_id') : null,
            ]);

            if ($role !== $administrateur->role) {
                $administrateur->definirRole($role);
            }
        });

        Log::channel(config('logging.default'))->notice('admin.permission_changed', [
            'acteur_id' => $request->user()->id,
            'administre_id' => $administrateur->id,
            'ancien' => $ancien,
            'nouveau' => $administrateur->fresh()->only(['name', 'role', 'faculte_id']),
            'adresse_ip' => $request->ip(),
        ]);

        return redirect()->route('admin.administrateurs.index')->with('success', "Le compte « {$administrateur->name} » a été mis à jour.");
    }

    /**
     * Attribution rapide du rôle (menu de la liste) : même mécanisme que
     * la modification complète (`AttributionRoleService`), réponse JSON
     * pour la modale ou redirection sinon.
     */
    public function attribuerRole(AttribuerRoleRequest $request, User $administrateur, AttributionRoleService $attribution): RedirectResponse|JsonResponse
    {
        try {
            $resultat = $attribution->attribuer(
                $request->user(),
                $administrateur,
                $request->validated('role'),
                $request->validated('faculte_id') !== null ? (int) $request->validated('faculte_id') : null,
                $request->validated('role_actuel'),
                'menu_comptes',
                $request->ip(),
            );
        } catch (AuthorizationException $e) {
            return $this->reponseAttributionEchec($request, 403, $e->getMessage());
        } catch (HttpException $e) {
            return $this->reponseAttributionEchec($request, $e->getStatusCode(), $e->getMessage());
        }

        $message = $resultat['inchange']
            ? "Le compte « {$administrateur->name} » possède déjà ce rôle."
            : "Rôle de « {$administrateur->name} » : {$resultat['ancien_role']} → {$resultat['nouveau_role']}.";

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'message' => $message] + $resultat);
        }

        return redirect()->route('admin.administrateurs.index')->with('success', $message);
    }

    /**
     * Recherche de comptes pour la modale d'attribution (page rôles) :
     * 10 résultats, sans données sensibles (ni hash ni token).
     */
    public function rechercher(Request $request): JsonResponse
    {
        $recherche = trim((string) $request->string('q'));

        abort_if(mb_strlen($recherche) < 2, 422, 'Recherche trop courte (2 caractères minimum).');

        $motif = '%'.str_replace(['%', '_', '\\'], '', $recherche).'%';

        $comptes = User::with('faculte:id,nom')
            ->where(fn ($q) => $q->where('name', 'like', $motif)->orWhere('email', 'like', $motif))
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'email', 'role', 'faculte_id']);

        return response()->json([
            'comptes' => $comptes->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'role_libelle' => $u->role_libelle,
                'faculte' => $u->faculte?->nom,
                'est_propre_compte' => $u->id === $request->user()->id,
            ]),
        ]);
    }

    private function reponseAttributionEchec(Request $request, int $statut, string $message): RedirectResponse|JsonResponse
    {
        if ($request->wantsJson()) {
            return response()->json(['ok' => false, 'message' => $message], $statut);
        }

        return back()->withErrors(['role' => $message])->withInput();
    }

    public function destroy(Request $request, User $administrateur): RedirectResponse
    {
        abort_if($administrateur->id === $request->user()->id, 403, 'Vous ne pouvez pas supprimer votre propre compte.');

        if ($administrateur->estSuperAdmin() && $administrateur->actif) {
            $autresActifs = User::where('role', 'super_admin')->where('actif', true)->where('id', '!=', $administrateur->id)->count();
            abort_if($autresActifs === 0, 403, 'Impossible : au moins un super-admin actif doit subsister.');
        }

        $nom = $administrateur->name;
        $administrateur->delete();

        Log::channel(config('logging.default'))->notice('admin.deleted', [
            'acteur_id' => $request->user()->id,
            'administre_id' => $administrateur->id,
            'adresse_ip' => $request->ip(),
        ]);

        return redirect()->route('admin.administrateurs.index')->with('success', "Le compte « {$nom} » a été supprimé.");
    }

    /**
     * Réinitialisation du mot de passe par un super-admin : nouveau
     * mot de passe fort, jamais journalisé.
     */
    public function reinitialiser(Request $request, User $administrateur): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'max:64', 'confirmed', Password::defaults()],
        ]);

        $administrateur->update(['password' => $request->input('password')]);

        // AUTH-01 (audit auth 12/10 — P2, correctif 13/10) : la
        // réinitialisation révoque TOUTES les sessions existantes du
        // compte cible — un cookie volé ne survit pas au reset.
        DB::table('sessions')->where('user_id', $administrateur->id)->delete();

        // Porte de secours : la réinitialisation coupe aussi le double
        // facteur (secret perdu = compte récupérable, traçé sans secret).
        // LOOP N : elle coupe aussi le code PIN secondaire.
        $mfaCoupe = (bool) $administrateur->mfa_active;

        if ($mfaCoupe) {
            $administrateur->forceFill([
                'mfa_secret' => null,
                'mfa_active' => false,
                'mfa_recovery_codes' => null,
            ])->save();
        }

        app(VerrouillageService::class)->purger($administrateur, 'reinitialisation_mot_de_passe');

        Log::channel(config('logging.default'))->notice('admin.password_reset', [
            'acteur_id' => $request->user()->id,
            'administre_id' => $administrateur->id,
            'mfa_coupe' => $mfaCoupe,
            'adresse_ip' => $request->ip(),
        ]);

        return back()->with('success', 'Mot de passe réinitialisé.');
    }

    /**
     * Kill-switch (AUTH-01, 13/10) : révocation immédiate de TOUTES les
     * sessions d'un compte compromis, sans toucher au mot de passe ni
     * aux données. Super_admin uniquement (groupe de routes) ; le compte
     * reste reconnectable — traçé sans secret, IP hachée.
     */
    public function purgerSessions(Request $request, User $administrateur): RedirectResponse
    {
        $supprimees = DB::table('sessions')->where('user_id', $administrateur->id)->delete();

        Log::channel(config('logging.default'))->notice('admin.sessions_purgees', [
            'acteur_id' => $request->user()->id,
            'administre_id' => $administrateur->id,
            'sessions' => $supprimees,
            'adresse_ip' => hash('sha256', $request->ip()),
        ]);

        return back()->with('success', "Sessions de « {$administrateur->name} » révoquées ({$supprimees}).");
    }
}
