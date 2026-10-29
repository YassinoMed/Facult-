<?php

namespace App\Modules\StudentDossiers\Policies;

use App\Models\User;
use App\Modules\StudentDossiers\Models\InscriptionDocument;

/**
 * LOOP-UNIREGISTRE-DOCUMENTS-REUTILISABLES-01 : isolation des
 * associations documentaires annuelles.
 *
 * Un administrateur ne voit/agit que dans le périmètre de sa faculté ;
 * les permissions restent celles du référentiel existant (aucune
 * permission inventée). Le téléchargement renvoie à la policy du
 * document permanent.
 */
class InscriptionDocumentPolicy
{
    private function dansPerimetre(User $user, InscriptionDocument $association): bool
    {
        $etudiant = $association->inscription?->etudiant;

        return $etudiant !== null
            && $user->peutAccederFaculte((int) $etudiant->faculte_id);
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('student_dossiers.view');
    }

    public function view(User $user, InscriptionDocument $association): bool
    {
        return $user->hasPermission('student_dossiers.view')
            && $this->dansPerimetre($user, $association);
    }

    /** Recalculer l'état documentaire de l'année (action de scolarité). */
    public function synchroniser(User $user, InscriptionDocument $association): bool
    {
        return $user->hasPermission('student_dossiers.update')
            && $this->dansPerimetre($user, $association);
    }

    public function download(User $user, InscriptionDocument $association): bool
    {
        return $association->student_document_id !== null
            && $user->hasPermission('student_documents.download')
            && $this->dansPerimetre($user, $association);
    }
}
