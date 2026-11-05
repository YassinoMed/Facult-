<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttribuerRoleRequest;
use App\Models\Faculte;
use App\Models\Iam\AffectationIam;
use App\Models\Iam\DemandeIam;
use App\Models\Iam\ProfilIam;
use App\Models\User;
use App\Services\AttributionRoleService;
use App\Support\Iam\CataloguePermissions;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Gestion des rôles : consultation unifiée (système + personnalisés),
 * attribution de rôles système via `AttributionRoleService`, et passerelle vers
 * les modules d'administration avancée IAM (CRUD profils, permissions, 4 yeux).
 */
class RoleController extends Controller
{
    public function index(AttributionRoleService $attribution, Request $request): View
    {
        $this->authorize('roles.view');

        // 1. Rôles système
        $rolesSysteme = [];
        foreach (Permissions::ROLES as $role) {
            $rolesSysteme[] = [
                'code' => $role,
                'nom' => ucwords(str_replace('_', ' ', $role)),
                'type' => 'systeme',
                'permissions' => $role === 'super_admin' ? Permissions::TOUTES : Permissions::pourRole($role),
                'effectif' => User::where('role', $role)->count(),
                'portee' => $role === 'admin_faculte' ? 'Facultaire' : 'Globale',
                'statut' => 'actif',
            ];
        }

        // 2. Rôles personnalisés (ProfilIam)
        $queryProfils = ProfilIam::query()->with(['permissions', 'faculte', 'createur']);

        // Isolation facultaire : un admin restreint ne voit que les rôles de sa faculté
        $restreinte = $request->user()?->faculteRestreinteId();
        if ($restreinte !== null) {
            $queryProfils->where(function ($q) use ($restreinte) {
                $q->where('faculte_id', $restreinte)->orWhereNull('faculte_id');
            });
        }

        // Recherche par mot-clé
        $recherche = trim((string) $request->input('q', ''));
        if ($recherche !== '') {
            $queryProfils->where(function ($q) use ($recherche) {
                $q->where('nom', 'like', "%{$recherche}%")
                    ->orWhere('code', 'like', "%{$recherche}%")
                    ->orWhere('description', 'like', "%{$recherche}%");
            });
        }

        // Filtre de statut
        $filtreStatut = (string) $request->input('statut', 'tous');
        if ($filtreStatut !== 'tous' && in_array($filtreStatut, ProfilIam::STATUTS, true)) {
            $queryProfils->where('statut', $filtreStatut);
        }

        $rolesPersonnalises = $queryProfils->latest('id')->paginate(15)->withQueryString();

        // 3. Indicateurs de gouvernance
        $totalAffectations = Schema::hasTable('iam_affectations')
            ? AffectationIam::where('statut', AffectationIam::STATUT_ACTIVE)->count()
            : 0;

        $demandesEnAttente = Schema::hasTable('iam_demandes')
            ? DemandeIam::where('statut', DemandeIam::STATUT_SOUMISE)->count()
            : 0;

        return view('admin.roles.index', [
            // Données historiques pour rétro-compatibilité
            'roles' => $rolesSysteme,
            'totalPermissions' => count(CataloguePermissions::toutes()),
            'peutAttribuer' => $request->user()->can('roles.manage'),
            'rolesAttribuables' => $attribution->rolesAttribuables($request->user()),
            'registrePermissions' => array_merge(...array_map(
                fn ($role) => [$role => $role === 'super_admin' ? Permissions::TOUTES : Permissions::pourRole($role)],
                Permissions::ROLES
            )),
            'facultes' => Faculte::where('active', true)->orderBy('nom')->get(['id', 'nom']),

            // Données enrichies IAM
            'rolesSysteme' => $rolesSysteme,
            'rolesPersonnalises' => $rolesPersonnalises,
            'recherche' => $recherche,
            'filtreStatut' => $filtreStatut,
            'indicateurs' => [
                'total_systeme' => count($rolesSysteme),
                'total_personnalises' => ProfilIam::count(),
                'total_affectations' => $totalAffectations,
                'demandes_en_attente' => $demandesEnAttente,
            ],
        ]);
    }

    /**
     * Attribution depuis la page des rôles : même service, mêmes gardes.
     * Réponse JSON pour la modale (ou redirection sinon).
     */
    public function attribuer(AttribuerRoleRequest $request, AttributionRoleService $attribution): JsonResponse|RedirectResponse
    {
        $cible = User::find($request->input('user_id'));

        if ($cible === null) {
            return $this->reponseEchec($request, 422, 'Compte cible introuvable.');
        }

        try {
            $resultat = $attribution->attribuer(
                $request->user(),
                $cible,
                $request->validated('role'),
                $request->validated('faculte_id') !== null ? (int) $request->validated('faculte_id') : null,
                $request->validated('role_actuel'),
                'page_roles',
                $request->ip(),
            );
        } catch (AuthorizationException $e) {
            return $this->reponseEchec($request, 403, $e->getMessage());
        } catch (HttpException $e) {
            return $this->reponseEchec($request, $e->getStatusCode(), $e->getMessage());
        }

        $message = $resultat['inchange']
            ? "Le compte « {$cible->name} » possède déjà ce rôle."
            : "Rôle de « {$cible->name} » : {$resultat['ancien_role']} → {$resultat['nouveau_role']}.";

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'message' => $message] + $resultat);
        }

        return redirect()->route('admin.roles.index')->with('success', $message);
    }

    private function reponseEchec(Request $request, int $statut, string $message): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return response()->json(['ok' => false, 'message' => $message], $statut);
        }

        return back()->withErrors(['role' => $message])->withInput();
    }
}
