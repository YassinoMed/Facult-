<?php

namespace App\Modules\StudentDossiers\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création du dossier permanent : un étudiant ne reçoit qu'UN dossier
 * (UNIQUE etudiant_id — la règle renvoie un message métier, la
 * contrainte BD reste le dernier rempart).
 */
class StoreStudentDossierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->hasPermission('student_dossiers.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'etudiant_id' => [
                'required',
                'exists:etudiants,id',
                Rule::unique('student_dossiers', 'etudiant_id'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'etudiant_id.required' => "L'étudiant est obligatoire.",
            'etudiant_id.exists' => "L'étudiant sélectionné est introuvable.",
            'etudiant_id.unique' => 'Un dossier existe déjà pour cet étudiant.',
        ];
    }
}
