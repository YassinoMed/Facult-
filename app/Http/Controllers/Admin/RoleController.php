<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\View\View;

/**
 * Gestion des rôles (lecture) : matrice rôles × permissions et effectifs.
 * L'attribution se fait via les comptes (`AdministrateurController@update`,
 * gardes anti-escalade) — aucun endpoint de modification de matrice ici :
 * les permissions vivent en code (`Permissions`), changement = déploiement
 * revu, jamais un clic.
 */
class RoleController extends Controller
{
    public function index(): View
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
        ]);
    }
}
