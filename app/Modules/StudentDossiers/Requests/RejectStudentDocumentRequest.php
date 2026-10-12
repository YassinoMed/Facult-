<?php

namespace App\Modules\StudentDossiers\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rejet avec motif OBLIGATOIRE : l'étudiant doit comprendre ce qui est
 * attendu pour corriger son document.
 */
class RejectStudentDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->hasPermission('student_documents.reject');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'motif' => 'required|string|max:500',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motif.required' => 'Le motif du rejet est obligatoire.',
            'motif.max' => 'Le motif ne doit pas dépasser 500 caractères.',
        ];
    }
}
