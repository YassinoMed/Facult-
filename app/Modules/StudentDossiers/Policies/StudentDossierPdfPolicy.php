<?php

namespace App\Modules\StudentDossiers\Policies;

use App\Models\User;
use App\Modules\StudentDossiers\Models\StudentDossier;

/**
 * PDF du dossier (§16) : hérite des contrôles du dossier (permission
 * de lecture + isolation faculté) et exige la permission dédiée
 * `student_dossiers.pdf` pour chaque action.
 */
class StudentDossierPdfPolicy extends StudentDossierPolicy
{
    private function autorisePdf(User $user, StudentDossier $dossier): bool
    {
        return $user->hasPermission('student_dossiers.pdf')
            && $user->peutAccederFaculte((int) $dossier->etudiant->faculte_id);
    }

    public function generatePdf(User $user, StudentDossier $dossier): bool
    {
        return $this->autorisePdf($user, $dossier);
    }

    public function viewPdf(User $user, StudentDossier $dossier): bool
    {
        return $this->autorisePdf($user, $dossier);
    }

    public function downloadPdf(User $user, StudentDossier $dossier): bool
    {
        return $this->autorisePdf($user, $dossier);
    }

    public function printPdf(User $user, StudentDossier $dossier): bool
    {
        return $this->autorisePdf($user, $dossier);
    }
}
