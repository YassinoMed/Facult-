<?php

namespace App\Modules\Referentiel\Policies;

use App\Models\User;

/**
 * Politique unique du référentiel LMD (§27/§29) : permission RBAC +
 * isolation faculté pour les entités rattachées à un établissement.
 * Enregistrée pour chaque modèle du module (ModuleServiceProvider).
 */
class ReferentielPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('referentiel.view');
    }

    public function view(User $user, object $modele): bool
    {
        return $user->hasPermission('referentiel.view')
            && $this->dansPerimetre($user, $modele);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('referentiel.manage');
    }

    public function update(User $user, object $modele): bool
    {
        return $user->hasPermission('referentiel.manage')
            && $this->dansPerimetre($user, $modele);
    }

    public function delete(User $user, object $modele): bool
    {
        return $user->hasPermission('referentiel.manage')
            && $this->dansPerimetre($user, $modele);
    }

    public function approuver(User $user, object $modele): bool
    {
        return $user->hasPermission('maquettes.approve')
            && $this->dansPerimetre($user, $modele);
    }

    /**
     * Isolation : restreint aux entités de la faculté de l'utilisateur.
     * Les rôles globaux (faculte_id null) voient tout.
     */
    private function dansPerimetre(User $user, object $modele): bool
    {
        $faculteId = $this->faculteDuModele($modele);

        return $faculteId === null || $user->peutAccederFaculte($faculteId);
    }

    private function faculteDuModele(object $modele): ?int
    {
        if (isset($modele->faculte_id)) {
            return (int) $modele->faculte_id;
        }

        foreach (['programme', 'domaine', 'mention', 'niveau', 'semestre'] as $relation) {
            if (method_exists($modele, $relation)) {
                try {
                    $parent = $modele->$relation;
                } catch (\Throwable) {
                    continue;
                }

                if ($parent !== null) {
                    $faculteId = $this->faculteDuModele($parent);

                    if ($faculteId !== null) {
                        return $faculteId;
                    }
                }
            }
        }

        // Entités globales (types de diplômes) : aucun périmètre.
        return null;
    }
}
