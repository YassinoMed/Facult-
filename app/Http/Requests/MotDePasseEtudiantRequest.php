<?php

namespace App\Http\Requests;

use App\Models\Etudiant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Changement de mot de passe depuis l'Espace Étudiant :
 * exige le mot de passe actuel (vérifié par bcrypt) et un nouveau mot de passe robuste.
 */
class MotDePasseEtudiantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return session()->has('etudiant_auth_id');
    }

    public function rules(): array
    {
        return [
            'current_password' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $etudiant = Etudiant::find(session('etudiant_auth_id'));

                    if (! $etudiant || empty($etudiant->password) || ! Hash::check($value, $etudiant->password)) {
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
            'password.min' => 'Le nouveau mot de passe doit contenir au moins 10 caractères.',
            'password.max' => 'Le nouveau mot de passe ne doit pas dépasser 64 caractères.',
            'password.confirmed' => 'La confirmation du nouveau mot de passe ne correspond pas.',
            'password.different' => 'Le nouveau mot de passe doit être différent de l\'actuel.',
            'password.letters' => 'Le nouveau mot de passe doit contenir au moins une lettre.',
            'password.numbers' => 'Le nouveau mot de passe doit contenir au moins un chiffre.',
            'password.uncompromised' => 'Ce mot de passe a été compromis dans une fuite de données connue. Veuillez en choisir un autre.',
        ];
    }
}
