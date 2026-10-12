<?php

namespace App\Policies;

use App\Models\Note;
use App\Models\User;

/**
 * Politique des notes académiques critiques :
 * - permission RBAC + périmètre de faculté (§6 isolation) ;
 * - une note verrouillée n'est modifiable que par un rôle supérieur
 *   (notes.unlock : super_admin / admin_scolarite) ;
 * - le verrouillage/déverrouillage est réservé à ces mêmes rôles.
 */
class NotePolicy
{
    public function view(User $user, Note $note): bool
    {
        return $user->hasPermission('notes.view')
            && $user->peutAccederFaculte((int) $note->etudiant->faculte_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('notes.create');
    }

    public function update(User $user, Note $note): bool
    {
        if (! $user->hasPermission('notes.update')
            || ! $user->peutAccederFaculte((int) $note->etudiant->faculte_id)) {
            return false;
        }

        return ! $note->estVerrouillee() || $user->hasPermission('notes.unlock');
    }

    public function delete(User $user, Note $note): bool
    {
        if (! $user->hasPermission('notes.delete')
            || ! $user->peutAccederFaculte((int) $note->etudiant->faculte_id)) {
            return false;
        }

        return ! $note->estVerrouillee() || $user->hasPermission('notes.unlock');
    }

    /**
     * Verrouiller / déverrouiller une note — rôles supérieurs uniquement.
     */
    public function lock(User $user, Note $note): bool
    {
        return $user->hasPermission('notes.unlock')
            && $user->peutAccederFaculte((int) $note->etudiant->faculte_id);
    }
}
