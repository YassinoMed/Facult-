<?php

namespace App\Modules\ParcoursAcademique\Policies;

use App\Models\Etudiant;
use App\Models\User;

/**
 * Module Parcours académique : permission RBAC + isolation
 * faculté (anti-IDOR/BOLA). Résolue pour chaque modèle du
 * module via son étudiant ; le périmètre est vérifié avant
 * la permission (hors faculté = 404).
 */
class ParcoursPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('parcours.view');
    }

    public function view(User $user, mixed $modele): bool
    {
        return $user->hasPermission('parcours.view')
            && $user->peutAccederFaculte((int) $this->faculteId($modele));
    }

    public function simuler(User $user, mixed $modele): bool
    {
        return $user->hasPermission('parcours.simuler')
            && $user->peutAccederFaculte((int) $this->faculteId($modele));
    }

    public function gerer(User $user, mixed $modele): bool
    {
        return $user->hasPermission('parcours.gerer')
            && $user->peutAccederFaculte((int) $this->faculteId($modele));
    }

    private function faculteId(mixed $modele): int
    {
        if ($modele instanceof Etudiant) {
            return (int) $modele->faculte_id;
        }

        $etudiant = $modele->etudiant ?? null;

        return (int) ($etudiant?->faculte_id ?? 0);
    }
}
