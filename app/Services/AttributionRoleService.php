<?php

namespace App\Services;

use App\Models\Faculte;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Mécanisme UNIQUE d'attribution des rôles (LOOP-ROLE-ASSIGNMENT-UX-01).
 *
 * Utilisé par le menu rapide des comptes ET par la page des rôles : les
 * règles vivent ici, jamais dans les contrôleurs, vues ou JS (la liste
 * affichée au navigateur n'est qu'une aide — la sécurité est serveur).
 *
 * Politique appliquée (= politique existante, unifiée) :
 * 1. Acteur super_admin uniquement.
 * 2. Jamais son propre rôle.
 * 3. Jamais rétrograder le dernier super-admin actif.
 * 4. `super_admin` attribuable par un super-admin (politique existante
 *    conservée, signalée sensible côté UI — aucun élargissement).
 * 5. `admin_faculte` ⇒ faculté active obligatoire, sinon NULL forcé.
 * 6. Changement atomique (transaction) + audit succès ; refus journalisés
 *    (politique : warning `admin.role_refuse` avec motif, sans secret).
 * 7. Concurrence optimiste : `role_actuel` attendu vérifié (409 si obsolète).
 * 8. Idempotence : même rôle + même faculté = succès sans écriture ni audit.
 */
final class AttributionRoleService
{
    /**
     * Rôles que l'acteur peut attribuer (aide UI — revérifié à l'exécution).
     *
     * @return array<int, array{code: string, libelle: string, nb_permissions: int, sensible: bool}>
     */
    public function rolesAttribuables(User $acteur): array
    {
        if (! $acteur->estSuperAdmin()) {
            return [];
        }

        $lignes = [];
        foreach (Permissions::ROLES as $role) {
            $lignes[] = [
                'code' => $role,
                'libelle' => $this->libelle($role),
                'nb_permissions' => $role === 'super_admin' ? count(Permissions::TOUTES) : count(Permissions::pourRole($role)),
                'sensible' => $role === 'super_admin',
            ];
        }

        return $lignes;
    }

    /**
     * @return array{inchange: bool, ancien_role: string|null, nouveau_role: string, role_libelle: string, faculte_id: int|null, faculte_nom: string|null, correlation: string}
     *
     * @throws AuthorizationException|ConflictHttpException|UnprocessableEntityHttpException
     */
    public function attribuer(
        User $acteur,
        User $cible,
        string $roleDemande,
        ?int $faculteId,
        ?string $roleActuelAttendu,
        string $source,
        ?string $adresseIp,
    ): array {
        $correlation = (string) Str::uuid();

        $refuser = function (string $motif, int $statut) use ($acteur, $cible, $roleDemande, $source, $adresseIp, $correlation): never {
            Log::channel(config('logging.default'))->warning('admin.role_refuse', [
                'acteur_id' => $acteur->id,
                'administre_id' => $cible->id,
                'demande_role' => $roleDemande,
                'motif' => $motif,
                'source' => $source,
                'correlation' => $correlation,
                'adresse_ip' => $adresseIp,
            ]);

            if ($statut === 403) {
                throw new AuthorizationException($motif);
            }

            if ($statut === 409) {
                throw new ConflictHttpException($motif);
            }

            throw new UnprocessableEntityHttpException($motif);
        };

        if (! $acteur->estSuperAdmin()) {
            $refuser('Acteur non autorisé à attribuer les rôles.', 403);
        }

        if (! Permissions::roleValide($roleDemande)) {
            $refuser('Rôle demandé inconnu.', 422);
        }

        if ($cible->id === $acteur->id) {
            $refuser('Vous ne pouvez pas modifier votre propre rôle.', 403);
        }

        if ($roleActuelAttendu !== null && $cible->role !== $roleActuelAttendu) {
            $refuser('Le rôle du compte a changé entre l’ouverture et la confirmation. Rechargez et réessayez.', 409);
        }

        if ($roleDemande === 'admin_faculte') {
            $faculte = Faculte::where('active', true)->find($faculteId);
            if ($faculte === null) {
                $refuser('Une faculté active est obligatoire pour le rôle administrateur de faculté.', 422);
            }
            $faculteId = $faculte->id;
        } else {
            $faculteId = null;
        }

        if ($cible->role === $roleDemande && (int) $cible->faculte_id === (int) $faculteId) {
            return [
                'inchange' => true,
                'ancien_role' => $cible->role,
                'nouveau_role' => $roleDemande,
                'role_libelle' => $cible->role_libelle,
                'faculte_id' => $cible->faculte_id,
                'faculte_nom' => $cible->faculte?->nom,
                'correlation' => $correlation,
            ];
        }

        if ($cible->estSuperAdmin() && $roleDemande !== 'super_admin' && $cible->actif) {
            $autresActifs = User::where('role', 'super_admin')->where('actif', true)->where('id', '!=', $cible->id)->count();
            if ($autresActifs === 0) {
                $refuser('Impossible : au moins un super-admin actif doit subsister.', 403);
            }
        }

        $ancien = ['role' => $cible->role, 'faculte_id' => $cible->faculte_id];

        DB::transaction(function () use ($cible, $roleDemande, $faculteId): void {
            $cible->update(['faculte_id' => $faculteId]);
            if ($roleDemande !== $cible->role) {
                $cible->definirRole($roleDemande);
            }
        });

        $cible->refresh();

        Log::channel(config('logging.default'))->notice('admin.role_attribue', [
            'acteur_id' => $acteur->id,
            'acteur_role' => $acteur->role,
            'administre_id' => $cible->id,
            'ancien' => $ancien,
            'nouveau' => ['role' => $cible->role, 'faculte_id' => $cible->faculte_id],
            'source' => $source,
            'correlation' => $correlation,
            'adresse_ip' => $adresseIp,
        ]);

        return [
            'inchange' => false,
            'ancien_role' => $ancien['role'],
            'nouveau_role' => $cible->role,
            'role_libelle' => $cible->role_libelle,
            'faculte_id' => $cible->faculte_id,
            'faculte_nom' => $cible->faculte?->nom,
            'correlation' => $correlation,
        ];
    }

    private function libelle(string $role): string
    {
        $utilisateur = new User;
        $utilisateur->role = $role;

        return $utilisateur->role_libelle;
    }
}
