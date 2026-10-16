<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Demande de changement d'adresse email via la popup du parcours public
 * « dossier existant » (sans login, après vérification d'identité par
 * code à usage unique).
 *
 * Règles (§2/§3) :
 * - SEULS `nouvel_email` + confirmation sont validés : tout autre champ
 *   (identifiant dossier, nom, statut, …) est IGNORÉ, même s'il figure
 *   dans la requête HTTP ;
 * - normalisation cohérente (minuscules + trim) des deux champs AVANT
 *   comparaison, comme `InscriptionRequest` ;
 * - l'autorisation réelle (jeton temporaire lié au dossier, en session)
 *   est vérifiée par le contrôleur, jamais par le navigateur.
 */
class DossierExistantEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nouvel_email' => mb_strtolower(trim((string) $this->input('nouvel_email', ''))),
            'nouvel_email_confirmation' => mb_strtolower(trim((string) $this->input('nouvel_email_confirmation', ''))),
        ]);
    }

    public function rules(): array
    {
        return [
            'nouvel_email' => ['required', 'string', 'email', 'max:254', 'confirmed'],
            'nouvel_email_confirmation' => ['required', 'string', 'email', 'max:254'],
        ];
    }

    public function messages(): array
    {
        return [
            'nouvel_email.required' => 'La nouvelle adresse email est obligatoire.',
            'nouvel_email.email' => 'Veuillez saisir une adresse email valide.',
            'nouvel_email.max' => 'L\'adresse email ne doit pas dépasser 254 caractères.',
            'nouvel_email.confirmed' => 'La confirmation ne correspond pas à la nouvelle adresse email.',
            'nouvel_email_confirmation.required' => 'Veuillez confirmer la nouvelle adresse email.',
            'nouvel_email_confirmation.email' => 'Veuillez saisir une adresse email valide.',
        ];
    }
}
