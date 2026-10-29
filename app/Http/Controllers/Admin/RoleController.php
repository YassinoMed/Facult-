<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttribuerRoleRequest;
use App\Models\Faculte;
use App\Models\User;
use App\Services\AttributionRoleService;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Gestion des rôles : consultation (matrice + effectifs) et attribution
 * via `AttributionRoleService` (même mécanisme que le menu des comptes).
 * `roles.view` ≠ droit d'attribuer : la mutation exige `roles.manage`
 * (détenue par super_admin uniquement — aucun privilège inventé).
 */
class RoleController extends Controller
{
    public function index(AttributionRoleService $attribution, Request $request): View
    {
        $roles = [];
        foreach (Permissions::ROLES as $role) {
            $roles[] = [
                'code' => $role,
                'permissions' => $role === 'super_admin' ? Permissions::TOUTES : Permissions::pourRole($role),
                'effectif' => User::where('role', $role)->count(),
            ];
        }

        return view('admin.roles.index', [
            'roles' => $roles,
            'totalPermissions' => count(Permissions::TOUTES),
            'peutAttribuer' => $request->user()->can('roles.manage'),
            'rolesAttribuables' => $attribution->rolesAttribuables($request->user()),
            'registrePermissions' => array_merge(...array_map(
                fn ($role) => [$role => $role === 'super_admin' ? Permissions::TOUTES : Permissions::pourRole($role)],
                Permissions::ROLES
            )),
            'facultes' => Faculte::where('active', true)->orderBy('nom')->get(['id', 'nom']),
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
