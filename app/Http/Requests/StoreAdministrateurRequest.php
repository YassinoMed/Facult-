<?php

namespace App\Http\Requests;

use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validation stricte de la création d'un administrateur back-office :
 * - rôle dans la matrice RBAC (jamais un rôle libre envoyé par le client) ;
 * - admin_faculte ⇒ rattachement obligatoire à une faculté ACTIVE ;
 * - les autres rôles sont globaux : faculte_id refusé (pas de périmètre) ;
 * - mot de passe fort (haché ensuite par le cast du modèle, jamais loggé).
 */
class StoreAdministrateurRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->estSuperAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'email' => strtolower(trim((string) $this->input('email', ''))),
            'role' => trim((string) $this->input('role', '')),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:254', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'max:64', 'confirmed', Password::defaults()],
            'role' => ['required', 'string', Rule::in(Permissions::ROLES)],
            // Obligatoire (et existante) UNIQUEMENT pour admin_faculte
            'faculte_id' => [
                Rule::requiredIf(fn () => $this->input('role') === 'admin_faculte'),
                'nullable',
                'integer',
                Rule::exists('facultes', 'id')->where('active', true),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Le nom de l\'administrateur est obligatoire.',
            'email.required' => 'L\'adresse email est obligatoire.',
            'email.email' => 'Veuillez saisir une adresse email valide.',
            'email.unique' => 'Cette adresse email est déjà utilisée par un compte back-office.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.min' => 'Le mot de passe doit contenir au moins 10 caractères.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
            'password.letters' => 'Le mot de passe doit contenir au moins une lettre.',
            'password.numbers' => 'Le mot de passe doit contenir au moins un chiffre.',
            'password.uncompromised' => 'Ce mot de passe a été compromis dans une fuite de données connue. Veuillez en choisir un autre.',
            'role.required' => 'Le rôle est obligatoire.',
            'role.in' => 'Le rôle choisi n\'est pas valide.',
            'faculte_id.required' => 'Un administrateur de faculté doit être rattaché à une faculté.',
            'faculte_id.exists' => 'La faculté sélectionnée est introuvable ou inactive.',
        ];
    }
}
