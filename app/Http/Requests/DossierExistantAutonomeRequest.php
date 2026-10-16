<?php

namespace App\Http\Requests;

use App\Support\SaisieIdentification;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Demande autonome de changement d'adresse email (sans l'ancienne adresse,
 * sans scolarité) : CIN + date de naissance + ANCIENNE adresse + MOT DE
 * PASSE du compte + nouvelle adresse + confirmation, en UNE seule
 * soumission.
 *
 * Règles :
 * - validation de FORMAT uniquement (aucune révélation d'existence : un
 *   CIN inexistant mais bien formé passe cette étape comme un dossier
 *   réel — la neutralité est assurée par le service, qui répond
 *   identiquement dans tous les cas) ;
 * - le champ `password` n'est JAMAIS remboursé en session (`old()`) :
 *   le gestionnaire d'exceptions du framework l'exclut du flash
 *   (`dontFlash`), même doctrine que la connexion étudiant ;
 * - normalisation cohérente (CIN/ emails : casse + espaces) avant usage ;
 * - date de naissance : saisie publique JJ/MM/AAAA (contrôle strict à
 *   4 niveaux) normalisée vers Y-m-d — format interne — via le helper
 *   partagé `SaisieIdentification` (une date impossible est rejetée).
 */
class DossierExistantAutonomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'cin' => mb_strtoupper(trim((string) $this->input('cin', ''))),
            'date_naissance' => SaisieIdentification::normaliserDateNaissance((string) $this->input('date_naissance', '')),
            'ancien_email' => mb_strtolower(trim((string) $this->input('ancien_email', ''))),
            'nouvel_email' => mb_strtolower(trim((string) $this->input('nouvel_email', ''))),
            'nouvel_email_confirmation' => mb_strtolower(trim((string) $this->input('nouvel_email_confirmation', ''))),
        ]);
    }

    public function rules(): array
    {
        return [
            'cin' => ['required', 'string', 'regex:/\A[0-9]{8}\z/'],
            'date_naissance' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) {
                $resultat = SaisieIdentification::validerDateNaissance((string) $value);

                if (($resultat['valid'] ?? false) !== true) {
                    $fail($resultat['message']);
                }
            }],
            'ancien_email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string'],
            'nouvel_email' => ['required', 'string', 'email', 'max:254', 'confirmed'],
            'nouvel_email_confirmation' => ['required', 'string', 'email', 'max:254'],
        ];
    }

    public function messages(): array
    {
        return [
            'cin.required' => 'Veuillez saisir votre numéro de CIN.',
            'cin.regex' => 'Le numéro de CIN doit être composé d\'exactement 8 chiffres.',
            'date_naissance.required' => 'La date de naissance est obligatoire.',
            'ancien_email.required' => 'Veuillez saisir votre ancienne adresse email.',
            'ancien_email.email' => 'Veuillez saisir une adresse email valide.',
            'password.required' => 'Veuillez saisir le mot de passe de votre compte.',
            'nouvel_email.required' => 'La nouvelle adresse email est obligatoire.',
            'nouvel_email.email' => 'Veuillez saisir une adresse email valide.',
            'nouvel_email.max' => 'L\'adresse email ne doit pas dépasser 254 caractères.',
            'nouvel_email.confirmed' => 'La confirmation ne correspond pas à la nouvelle adresse email.',
            'nouvel_email_confirmation.required' => 'Veuillez confirmer la nouvelle adresse email.',
            'nouvel_email_confirmation.email' => 'Veuillez saisir une adresse email valide.',
        ];
    }
}
