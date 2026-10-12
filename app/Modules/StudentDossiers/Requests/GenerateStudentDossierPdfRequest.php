<?php

namespace App\Modules\StudentDossiers\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Génération explicite du PDF : aucune donnée client requise (le
 * dossier vient de la route, scopé + policé) — autorisation seule.
 */
class GenerateStudentDossierPdfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->hasPermission('student_dossiers.pdf');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
