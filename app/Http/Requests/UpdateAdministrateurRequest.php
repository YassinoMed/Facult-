<?php

namespace App\Http\Requests;

use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Modification d'un administrateur : nom, rôle RBAC valide,
 * rattachement faculté (admin_faculte uniquement).
 */
class UpdateAdministrateurRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->estSuperAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'role' => trim((string) $this->input('role', '')),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'role' => ['required', 'string', Rule::in(Permissions::ROLES)],
            'faculte_id' => [
                Rule::requiredIf(fn () => $this->input('role') === 'admin_faculte'),
                'nullable',
                'integer',
                Rule::exists('facultes', 'id')->where('active', true),
            ],
        ];
    }
}
