<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirmation du changement d'adresse email : seul le code à usage
 * unique envoyé à l'adresse DÉJÀ VÉRIFIÉE du dossier (l'adresse
 * actuellement enregistrée) est accepté — preuve supplémentaire §3.
 * La nouvelle adresse elle-même n'est JAMAIS reprise de la requête :
 * elle provient du serveur (valeur liée au code lors de la demande,
 * anti-substitution).
 */
class DossierExistantEmailConfirmationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Le code de confirmation est obligatoire.',
            'code.regex' => 'Le code de confirmation est composé de 6 chiffres.',
        ];
    }
}
