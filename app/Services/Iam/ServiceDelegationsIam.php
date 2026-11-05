<?php

namespace App\Services\Iam;

use App\Models\Faculte;
use App\Models\Iam\DelegationIam;
use App\Models\User;
use App\Support\Iam\CataloguePermissions;
use App\Support\Iam\MoteurAutorisation;
use Illuminate\Support\Facades\DB;

/**
 * Service de délégations de permissions IAM (LOOP 43-BIS).
 *
 * Règles impératives :
 * 1. Le délégant doit détenir DIRECTEMENT la permission (RBAC). Une
 *    délégation ne peut pas se construire sur une autre délégation (pas
 *    de chaîne). Si le délégant perd la permission, la délégation devient
 *    immédiatement invalide.
 * 2. Le délégataire reçoit la permission uniquement dans le cadre de la
 *    faculté du délégant (ou centrale si aucune faculté).
 * 3. La fenêtre est bornée et expirée sans scheduler actif (vérification
 *    immédiate à chaque évaluation d'autorisation).
 * 4. La révoquer peut être faite par le délégant ou par tout titulaire
 *    de `demandes.assign`.
 * 5. Un délégataire ne peut pas redéleguer (aucune chaîne).
 */
final class ServiceDelegationsIam
{
    public const DUREE_MAX_JOURS = 90;

    public function deleguer(
        User $delegant,
        User $delegataire,
        string $permissionCode,
        ?Faculte $faculte = null,
        ?int $dureeJours = null,
        ?string $motif = null,
        ?User $acteur = null,
    ): DelegationIam {
        if (! CataloguePermissions::existe($permissionCode)) {
            throw new \InvalidArgumentException("Permission inconnue : {$permissionCode}.");
        }

        if (! CataloguePermissions::administrable($permissionCode)) {
            throw new \InvalidArgumentException("Cette permission n'est pas administrée par la gouvernance IAM : {$permissionCode}.");
        }

        // Vérification immédiate : le délégant doit détenir la permission
        // dans le code (rbac), pas par délégation.
        if (! $delegant->hasPermission($permissionCode)) {
            throw new \InvalidArgumentException("Le délégant ne détient pas la permission {$permissionCode} en propre.");
        }

        $duree = $dureeJours ?? config('iam.duree_max_delegation_jours', 90);

        if ($duree < 1 || $duree > self::DUREE_MAX_JOURS) {
            throw new \InvalidArgumentException('Durée invalide (1 à '.self::DUREE_MAX_JOURS." jours) : {$duree}.");
        }

        $finLe = now()->copy()->addDays($duree);

        return DB::transaction(function () use ($delegant, $delegataire, $permissionCode, $faculte, $finLe, $motif, $acteur): DelegationIam {
            $delegation = DelegationIam::query()->create([
                'delegant_id' => $delegant->id,
                'delegataire_id' => $delegataire->id,
                'permission_code' => $permissionCode,
                'faculte_id' => $faculte?->id ?? $delegant->faculteRestreinteId(),
                'statut' => DelegationIam::STATUT_ACTIVE,
                'debut_le' => now(),
                'fin_le' => $finLe,
                'motif' => $motif,
                'cree_par_id' => $acteur?->id,
            ]);

            MoteurAutorisation::marquerChangement($delegataire->id);

            return $delegation;
        });
    }

    public function revoquer(DelegationIam $delegation, ?User $acteur = null): DelegationIam
    {
        return DB::transaction(function () use ($delegation, $acteur): DelegationIam {
            $delegation->statut = DelegationIam::STATUT_REVOQUEE;
            $delegation->revoque_par_id = $acteur?->id;
            $delegation->revoque_le = now();
            $delegation->save();

            MoteurAutorisation::marquerChangement($delegation->delegataire_id);

            return $delegation->fresh();
        });
    }

    public function estActive(DelegationIam $delegation): bool
    {
        return $delegation->statut === DelegationIam::STATUT_ACTIVE
            && $delegation->debut_le->isPast()
            && $delegation->fin_le->isFuture();
    }

    public function peutValider(User $utilisateur, string $permissionCode): bool
    {
        // Le délégataire détient-il la permission ?
        if ($utilisateur->hasPermission($permissionCode)) {
            return true;
        }

        // Par délégation active ?
        $delegation = DelegationIam::query()
            ->where('delegataire_id', $utilisateur->id)
            ->where('permission_code', $permissionCode)
            ->where('statut', DelegationIam::STATUT_ACTIVE)
            ->where('debut_le', '<=', now())
            ->where('fin_le', '>', now())
            ->first();

        if ($delegation === null) {
            return false;
        }

        // Le délégant doit détenir DIRECTEMENT la permission.
        return $delegation->delegant->hasPermission($permissionCode);
    }
}
