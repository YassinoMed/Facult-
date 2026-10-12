<?php

namespace App\Policies;

use App\Models\InscriptionAcademique;
use App\Models\User;

/**
 * Politique des inscriptions académiques (§12-13 : une nouvelle année
 * universitaire crée une INSCRIPTION rattachée à l'étudiant existant,
 * jamais un nouvel étudiant).
 */
class InscriptionAcademiquePolicy
{
    public function view(User $user, InscriptionAcademique $inscription): bool
    {
        return $user->hasPermission('archives.view')
            && $user->peutAccederFaculte((int) $inscription->etudiant->faculte_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('archives.create');
    }

    public function update(User $user, InscriptionAcademique $inscription): bool
    {
        return $user->hasPermission('archives.update')
            && $user->peutAccederFaculte((int) $inscription->etudiant->faculte_id);
    }

    public function delete(User $user, InscriptionAcademique $inscription): bool
    {
        return $user->hasPermission('archives.delete')
            && $user->peutAccederFaculte((int) $inscription->etudiant->faculte_id);
    }
}
