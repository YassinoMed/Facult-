<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Téléversement d'un document académique.
 * Le fichier subit une double validation : FormRequest (mimes, taille)
 * puis DocumentService (extension, MIME réel, contenu).
 */
class StoreDocumentAcademiqueRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! ($this->user()?->hasPermission('documents.upload') ?? false)) {
            return false;
        }

        $dossier = $this->route('dossier');

        return $dossier === null
            || $this->user()->peutAccederFaculte((int) $dossier->etudiant->faculte_id);
    }

    public function rules(): array
    {
        return [
            'type_document' => [
                'required',
                Rule::in([
                    'releve_notes', 'attestation', 'diplome', 'certificat', 'convention', 'autre',
                ]),
            ],
            'annee_universitaire_id' => [
                'nullable',
                'integer',
                Rule::exists('annees_universitaires', 'id'),
            ],
            'document' => [
                'required',
                'file',
                'max:10240', // 10 Mo (en Ko pour le validateur Laravel)
                'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx,txt',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'type_document.required' => 'Veuillez sélectionner le type de document.',
            'type_document.in' => 'Le type de document sélectionné n\'est pas valide.',
            'annee_universitaire_id.exists' => 'L\'année universitaire sélectionnée est introuvable.',
            'document.required' => 'Veuillez sélectionner un fichier à téléverser.',
            'document.file' => 'Le document doit être un fichier valide.',
            'document.max' => 'Le document ne doit pas dépasser 10 Mo.',
            'document.mimes' => 'Formats acceptés : PDF, JPG, PNG, DOC, DOCX, XLS, XLSX, TXT.',
        ];
    }
}
