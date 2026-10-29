<?php

namespace App\Modules\GouvernanceConformite\Observers;

use App\Models\User;
use App\Modules\GouvernanceConformite\Models\JournalChangementPermission;
use Illuminate\Support\Facades\Log;

/**
 * Lot 19 — Observer des permissions utilisateur.
 *
 * Capture STRUCTURÉE (table dédiée requêtable) des créations de comptes
 * et des changements de rôle — sans modifier AUCUN contrôleur existant
 * (l'attribution reste `AdministrateurController` → `creerCompteBackOffice`).
 *
 * Fail-soft : si le journal est indisponible, la création/modification de
 * l'utilisateur N'EST JAMAIS bloquée (warning loggé, aucune exception
 * remontée à l'usager).
 */
class ObserverPermissionsUser
{
    /**
     * Création : `creerCompteBackOffice` attribue le rôle APRÈS le create()
     * (§P3-17 : rôle jamais mass-assigné) — l'événement `created` voit un
     * rôle NULL et est ignoré ; la ligne « création » est capturée par le
     * `updated` qui suit (ancien_role NULL = création de compte).
     */
    public function created(User $utilisateur): void
    {
        if ($utilisateur->role !== null) {
            $this->tracer($utilisateur, null, $utilisateur->role);
        }
    }

    public function updated(User $utilisateur): void
    {
        if (! $utilisateur->wasChanged('role') || $utilisateur->role === null) {
            return;
        }

        $this->tracer($utilisateur, $utilisateur->getOriginal('role'), $utilisateur->role);
    }

    private function tracer(User $utilisateur, ?string $ancienRole, string $nouveauRole): void
    {
        try {
            $acteur = User::find(auth()->id());

            JournalChangementPermission::create([
                'utilisateur_id' => $utilisateur->id,
                'ancien_role' => $ancienRole,
                'nouveau_role' => $nouveauRole,
                'acteur_id' => $acteur?->id,
                'source' => $acteur !== null ? 'http' : 'interne',
            ]);
        } catch (\Throwable $e) {
            // Fail-soft : la gouvernance ne bloque JAMAIS une création de compte.
            Log::warning('gouvernance.journal_permissions.indisponible', [
                'utilisateur_id' => $utilisateur->id,
                'erreur' => $e->getMessage(),
            ]);
        }
    }
}
