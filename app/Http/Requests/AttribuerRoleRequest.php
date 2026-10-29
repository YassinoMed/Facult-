<?php

namespace App\Http\Requests;

use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Attribution rapide d'un rôle (menu comptes / page rôles) : le contrôle
 * d'autorisation métier vit dans `AttributionRoleService` ; cette requête
 * ne fait que valider la forme (jamais la sécurité).
 */
class AttribuerRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => ['sometimes', 'integer', Rule::exists('users', 'id')],
            'role' => ['required', 'string', Rule::in(Permissions::ROLES)],
            'faculte_id' => [
                Rule::requiredIf(fn () => $this->input('role') === 'admin_faculte'),
                'nullable',
                'integer',
                Rule::exists('facultes', 'id')->where('active', true),
            ],
            'role_actuel' => ['nullable', 'string', Rule::in(Permissions::ROLES)],
        ];
    }
}
