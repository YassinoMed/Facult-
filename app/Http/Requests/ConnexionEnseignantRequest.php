<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConnexionEnseignantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'identifiant' => trim($this->identifiant ?? ''),
        ]);
    }

    public function rules(): array
    {
        return [
            'identifiant' => 'required|string|max:255',
            'password' => 'required|string',
        ];
    }

    public function messages(): array
    {
        return [
            'identifiant.required' => 'Veuillez saisir votre adresse email professionnelle ou votre matricule.',
            'identifiant.max' => "L'identifiant saisi est trop long.",
            'password.required' => 'Veuillez saisir votre mot de passe.',
        ];
    }
}
