<?php

namespace App\Modules\StudentDossiers\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Remplacement versionné (§3) : même validation de fichier que
 * l'upload — la catégorie et le type restent ceux du document.
 */
class ReplaceStudentDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->hasPermission('student_documents.replace');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var array<int, string> $extensions */
        $extensions = config('student-dossiers.extensions', []);

        return [
            'document' => [
                'required',
                'file',
                'max:'.((int) config('student-dossiers.taille_max', 10 * 1024 * 1024) / 1024),
                'mimes:'.implode(',', $extensions),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document.required' => 'Le nouveau fichier est obligatoire.',
            'document.max' => 'Fichier trop volumineux (maximum 10 Mo).',
            'document.mimes' => 'Formats acceptés : PDF, JPG, PNG, WebP.',
        ];
    }
}
