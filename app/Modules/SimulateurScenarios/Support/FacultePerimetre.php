<?php

namespace App\Modules\SimulateurScenarios\Support;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Lot 14 — Résolution du périmètre facultaire côté serveur.
 *
 * RÉUTILISATION (zéro duplication) : la règle vit dans les primitives
 * `User::peutAccederFaculte()` / `User::faculteRestreinteId()` — les mêmes
 * qu'utilise ServiceObservabilite::perimetreEffectif (Data Mesh). Ce
 * résolveur applique la règle existante au simulateur, sans la redéfinir :
 * un admin_faculte ne consulte que sa faculté, même en manipulant le
 * paramètre de requête (anti-IDOR/BOLA).
 */
final class FacultePerimetre
{
    /**
     * Faculté effective : refus explicite hors périmètre, forçage de la
     * faculté restreinte, null = transversal (aucun périmètre verrouillé).
     *
     * @throws AuthorizationException faculté demandée hors périmètre
     */
    public static function resoudre(User $utilisateur, ?int $demandee): ?int
    {
        if ($demandee !== null && ! $utilisateur->peutAccederFaculte($demandee)) {
            throw new AuthorizationException('Simulateur de scénarios : faculté hors périmètre.');
        }

        return $utilisateur->faculteRestreinteId() ?? $demandee;
    }
}
