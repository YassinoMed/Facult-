<?php

namespace App\Modules\OnlineRequests\Services;

use App\Models\User;
use App\Modules\OnlineRequests\Models\RequestDelegation;
use App\Services\AuditService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Délégations de l'autorité de validation des demandes (LOT 01,
 * audit §F1). Le titulaire de `demandes.approve` délègue ce pouvoir
 * (approbation ET rejet) à un collègue pour une fenêtre bornée :
 *
 * - jamais d'auto-délégation ni de chaîne (le délégant doit détenir
 *   lui-même la permission, pas une délégation) ;
 * - fenêtre bornée (durée maximale DUREE_MAX_JOURS) et révocable ;
 * - délégant et délégataire d'une même faculté, ou délégant central ;
 * - la séparation des fonctions (quatre yeux) s'applique pleinement
 *   au délégataire : déléguer n'autorise jamais à s'auto-approuver ;
 * - chaque création/révocation est auditée (tracerReferentiel :
 *   action administrative sans étudiant).
 */
class DelegationDemandeService
{
    /** Fenêtre maximale d'une délégation, en jours calendaires. */
    public const DUREE_MAX_JOURS = 90;

    public function __construct(private readonly AuditService $audit) {}

    /**
     * Crée une délégation active de l'autorité de validation.
     *
     * @param  array{motif: string, valide_depuis?: CarbonInterface, valide_jusqua: CarbonInterface, faculte_id?: ?int}  $parametres
     */
    public function deleguer(User $deleguant, User $delegataire, array $parametres): RequestDelegation
    {
        $depuis = $parametres['valide_depuis'] ?? now();
        $jusqua = $parametres['valide_jusqua'];

        if (! $deleguant->hasPermission('demandes.approve')) {
            throw ValidationException::withMessages([
                'delegue_par' => 'Seul le titulaire du pouvoir de validation peut déléguer.',
            ]);
        }

        if ((int) $deleguant->id === (int) $delegataire->id) {
            throw ValidationException::withMessages([
                'delegue_a' => 'Une délégation ne peut pas être accordée à soi-même.',
            ]);
        }

        if ($deleguant->faculte_id !== null && $delegataire->faculte_id !== null
            && (int) $deleguant->faculte_id !== (int) $delegataire->faculte_id) {
            throw ValidationException::withMessages([
                'delegue_a' => 'Le délégataire doit appartenir à la même faculté que le délégant.',
            ]);
        }

        if ($jusqua->lessThanOrEqualTo($depuis) || $jusqua->lessThan(now())) {
            throw ValidationException::withMessages([
                'valide_jusqua' => 'La fenêtre de délégation doit être future et se terminer après son début.',
            ]);
        }

        if ($jusqua->greaterThan($depuis->copy()->addDays(self::DUREE_MAX_JOURS))) {
            throw ValidationException::withMessages([
                'valide_jusqua' => 'Délégation trop longue (maximum '.self::DUREE_MAX_JOURS.' jours).',
            ]);
        }

        $delegation = RequestDelegation::create([
            'delegue_par' => $deleguant->id,
            'delegue_a' => $delegataire->id,
            'faculte_id' => $parametres['faculte_id'] ?? $deleguant->faculte_id,
            'valide_depuis' => $depuis,
            'valide_jusqua' => $jusqua,
            'motif' => $parametres['motif'],
            'created_by' => $deleguant->id,
        ]);

        $this->audit->tracerReferentiel(
            'delegation_validation.creee',
            'request_delegation',
            (int) $delegation->id,
            null,
            [
                'delegue_par' => $deleguant->id,
                'delegue_a' => $delegataire->id,
                'valide_depuis' => $depuis->toDateTimeString(),
                'valide_jusqua' => $jusqua->toDateTimeString(),
                'motif' => $parametres['motif'],
            ],
        );

        return $delegation;
    }

    /**
     * Révoque une délégation : le délégant, ou tout titulaire de
     * `demandes.assign`, peut y mettre fin immédiatement.
     */
    public function revoquer(RequestDelegation $delegation, User $acteur): RequestDelegation
    {
        if ((int) $delegation->delegue_par !== (int) $acteur->id
            && ! $acteur->hasPermission('demandes.assign')) {
            throw ValidationException::withMessages([
                'delegation' => 'Seul le délégant ou un responsable d\'affectation peut révoquer cette délégation.',
            ]);
        }

        return DB::transaction(function () use ($delegation, $acteur) {
            /** @var RequestDelegation $verrouillee */
            $verrouillee = RequestDelegation::whereKey($delegation->id)->lockForUpdate()->firstOrFail();

            if ($verrouillee->revoked_at === null) {
                $verrouillee->update(['revoked_at' => now()]);

                $this->audit->tracerReferentiel(
                    'delegation_validation.revoquee',
                    'request_delegation',
                    (int) $verrouillee->id,
                    ['revoked_at' => null],
                    ['revoked_at' => now()->toDateTimeString(), 'acteur' => $acteur->id],
                );
            }

            return $verrouillee->fresh();
        });
    }

    /**
     * L'utilisateur détient-il l'autorité de validation, directement
     * (RBAC) ou par délégation active ? Le délégant doit toujours
     * détenir la permission au moment de la résolution — une délégation
     * perd son effet dès que son émetteur perd le pouvoir.
     *
     * Point de résolution unique : Gate `demandes.approve/reject`,
     * policies, FormRequests et service des demandes passent ici.
     */
    public function peutValider(User $utilisateur, string $permission): bool
    {
        if ($utilisateur->hasPermission($permission)) {
            return true;
        }

        $delegationsActives = RequestDelegation::query()
            ->where('delegue_a', $utilisateur->id)
            ->whereNull('revoked_at')
            ->where('valide_depuis', '<=', now())
            ->where('valide_jusqua', '>=', now())
            ->with('deleguant')
            ->get();

        foreach ($delegationsActives as $delegation) {
            $deleguant = $delegation->deleguant;

            if ($deleguant !== null && $deleguant->hasPermission('demandes.approve')) {
                return true;
            }
        }

        return false;
    }
}
