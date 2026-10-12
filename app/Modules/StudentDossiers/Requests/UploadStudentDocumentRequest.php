<?php

namespace App\Modules\StudentDossiers\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Upload sécurisé (§6) : première barrière (extension/MIME/taille +
 * catégorie et type issus de la config). Le stockage re-valide le
 * CONTENU en profondeur — lui seul fait foi.
 */
class UploadStudentDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->hasPermission('student_documents.upload');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var array<int, string> $extensions */
        $extensions = config('student-dossiers.extensions', []);
        /** @var array<string, string> $categories */
        $categories = config('student-dossiers.categories', []);
        /** @var array<string, array<int, string>> $types */
        $types = config('student-dossiers.document_types', []);

        $categorie = $this->input('category');

        return [
            'document' => [
                'required',
                'file',
                'max:'.((int) config('student-dossiers.taille_max', 10 * 1024 * 1024) / 1024),
                'mimes:'.implode(',', $extensions),
            ],
            'category' => ['required', Rule::in(array_keys($categories))],
            'document_type' => ['required', Rule::in($types[$categorie] ?? ['autre'])],
            'title' => 'required|string|max:150',
            'description' => 'nullable|string|max:500',
            'expires_at' => 'nullable|date|after:today',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document.required' => 'Le fichier est obligatoire.',
            'document.file' => 'Le téléversement est invalide.',
            'document.max' => 'Fichier trop volumineux (maximum 10 Mo).',
            'document.mimes' => 'Formats acceptés : PDF, JPG, PNG, WebP.',
            'category.required' => 'La catégorie est obligatoire.',
            'category.in' => 'La catégorie sélectionnée est invalide.',
            'document_type.required' => 'Le type de document est obligatoire.',
            'document_type.in' => 'Le type de document est invalide pour cette catégorie.',
            'title.required' => 'Le titre est obligatoire.',
        ];
    }
}
