<?php

namespace App\Http\Requests\Iam;

use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation de création d'un rôle personnalisé.
 */
class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('roles.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'min:3',
                'max:60',
                'regex:/^[a-z0-9_\-]+$/',
                Rule::notIn(Permissions::ROLES),
                'unique:iam_profils,code',
            ],
            'nom' => ['required', 'string', 'min:3', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'faculte_id' => ['nullable', 'integer', 'exists:facultes,id'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Le code technique est obligatoire.',
            'code.regex' => 'Le code technique ne doit comporter que des lettres minuscules, chiffres, tirets et underscores.',
            'code.not_in' => 'Ce code technique entre en conflit avec un rôle système protégé.',
            'code.unique' => 'Ce code technique est déjà utilisé par un autre rôle.',
            'nom.required' => 'Le libellé du rôle est obligatoire.',
            'nom.min' => 'Le nom du rôle doit comporter au moins :min caractères.',
            'faculte_id.exists' => 'La faculté sélectionnée est introuvable.',
        ];
    }
}
