<?php

namespace App\Http\Requests;

use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Changement de mot de passe depuis l'Espace Enseignant :
 * exige le mot de passe actuel (vérifié par bcrypt) et un nouveau mot de passe robuste.
 */
class MotDePasseEnseignantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return session()->has('enseignant_auth_id');
    }

    public function rules(): array
    {
        return [
            'current_password' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $enseignant = Teacher::find(session('enseignant_auth_id'));

                    if (! $enseignant || empty($enseignant->password) || ! Hash::check($value, $enseignant->password)) {
                        $fail('Le mot de passe actuel est incorrect.');
                    }
                },
            ],
            'password' => [
                'required',
                'string',
                'max:64',
                'confirmed',
                'different:current_password',
                Password::defaults(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'Veuillez saisir votre mot de passe actuel.',
            'password.required' => 'Le nouveau mot de passe est obligatoire.',
            'password.max' => 'Le nouveau mot de passe ne doit pas dépasser 64 caractères.',
            'password.confirmed' => 'La confirmation du nouveau mot de passe ne correspond pas.',
            'password.different' => "Le nouveau mot de passe doit être différent de l'actuel.",
        ];
    }
}
