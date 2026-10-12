<?php

namespace App\Modules\StudentDossiers\Requests;

/**
 * Remplacement côté étudiant : mêmes règles fichier, ownership via session.
 */
class RemplacerMonDocumentRequest extends ReplaceStudentDocumentRequest
{
    public function authorize(): bool
    {
        return session()->has('etudiant_auth_id');
    }
}
