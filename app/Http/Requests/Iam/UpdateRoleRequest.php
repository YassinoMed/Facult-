<?php

namespace App\Http\Requests\Iam;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation de modification des caractéristiques d'un rôle.
 */
class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('roles.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'min:3', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'faculte_id' => ['nullable', 'integer', 'exists:facultes,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'nom.required' => 'Le libellé du rôle est obligatoire.',
            'nom.min' => 'Le libellé doit comporter au moins :min caractères.',
            'faculte_id.exists' => 'La faculté sélectionnée est invalide.',
        ];
    }
}
