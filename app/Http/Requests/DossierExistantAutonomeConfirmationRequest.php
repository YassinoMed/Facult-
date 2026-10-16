<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirmation autonome du changement d'adresse email : seul le code à
 * usage unique envoyé à la NOUVELLE adresse (candidate) est accepté.
 * L'autorisation temporaire `email_change` (jeton opaque en session,
 * jamais dans l'URL) désigne le dossier — AUCUN identifiant repris de
 * la requête, et la candidate provient du serveur (liée au code,
 * anti-substitution).
 */
class DossierExistantAutonomeConfirmationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code_autonome' => ['required', 'string', 'regex:/\A[0-9]{6}\z/'],
        ];
    }

    public function messages(): array
    {
        return [
            'code_autonome.required' => 'Le code de confirmation envoyé à votre nouvelle adresse est obligatoire.',
            'code_autonome.regex' => 'Le code de confirmation est composé de 6 chiffres.',
        ];
    }
}
