<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirmation du changement d'adresse email par le code envoyé à la
 * NOUVELLE adresse (adresse candidate). Le champ `code_nouvelle` est
 * volontairement distinct de `code` (première preuve, vers l'adresse
 * actuelle) : en cas d'erreur de validation, la popup rouvre la bonne
 * étape (`code-nouvelle`) au lieu de retomber sur la première.
 *
 * La nouvelle adresse elle-même n'est JAMAIS reprise de la requête :
 * elle provient du serveur (valeur liée au code lors de l'émission,
 * anti-substitution).
 */
class DossierExistantEmailNouvelleConfirmationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code_nouvelle' => ['required', 'string', 'regex:/\A[0-9]{6}\z/'],
        ];
    }

    public function messages(): array
    {
        return [
            'code_nouvelle.required' => 'Le code de confirmation envoyé à votre nouvelle adresse est obligatoire.',
            'code_nouvelle.regex' => 'Le code de confirmation est composé de 6 chiffres.',
        ];
    }
}
