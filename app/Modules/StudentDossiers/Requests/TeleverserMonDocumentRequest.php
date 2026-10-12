<?php

namespace App\Modules\StudentDossiers\Requests;

/**
 * Upload côté étudiant : mêmes règles que le back-office (héritage),
 * autorisation = session possédant le dossier (ownership, pas de rôle).
 */
class TeleverserMonDocumentRequest extends UploadStudentDocumentRequest
{
    public function authorize(): bool
    {
        return session()->has('etudiant_auth_id');
    }
}
