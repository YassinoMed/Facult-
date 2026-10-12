<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation du scan de document d'identité (§3) :
 * - type de document dans la liste supportée (CIN tunisienne, passeport) ;
 * - fichier obligatoire — la validation PROFONDE (MIME réel, signature,
 *   taille, polyglot) est refaite côté serveur par
 *   IdentityDocumentService::validerFichier(), l'extension seule
 *   n'est jamais crue (§14).
 */
class ScanIdentityDocumentRequest extends FormRequest
{
    public const TYPES_DOCUMENTS = ['cin', 'passeport'];

    public function authorize(): bool
    {
        return true; // endpoint public — CSRF + rate limiting identity.scan
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'document_type' => mb_strtolower(trim((string) $this->input('document_type', ''))),
        ]);
    }

    public function rules(): array
    {
        $tailleMaxKo = (int) ceil((int) config('identity.upload.taille_max') / 1024);

        return [
            'document_type' => ['required', 'string', Rule::in(self::TYPES_DOCUMENTS)],
            'document' => [
                'required',
                'file',
                'max:'.$tailleMaxKo,
                'mimes:jpg,jpeg,png,pdf',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'document_type.required' => 'Veuillez choisir le type de votre document d\'identité.',
            'document_type.in' => 'Type de document non pris en charge (CIN ou passeport).',
            'document.required' => 'Veuillez téléverser votre document d\'identité.',
            'document.file' => 'Le document envoyé n\'est pas un fichier valide.',
            'document.max' => 'Le document dépasse la taille maximale autorisée.',
            'document.mimes' => 'Formats acceptés : JPG, JPEG, PNG, PDF.',
        ];
    }
}
