<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation de la recherche de pré-remplissage (audit §P0-4 + LOOP saisies §3) :
 * - CIN : exactement 8 chiffres ASCII (référentiel tunisien, forme
 *   uniquement — zéros initiaux préservés, espaces/lettres/tirets REFUSÉS,
 *   jamais convertis) ;
 * - date de naissance : SECOND FACTEUR obligatoire — le CIN seul
 *   (identifiant semi-public) ne doit jamais suffire à récupérer
 *   les données personnelles d'un étudiant.
 */
class VerifierCinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // endpoint public — protection par CSRF + rate limiting
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'cin' => is_string($this->cin) ? $this->cin : '',
            'date_naissance' => trim((string) $this->input('date_naissance', '')),
        ]);
    }

    public function rules(): array
    {
        return [
            'cin' => ['required', 'string', 'regex:/\A[0-9]{8}\z/'],
            'date_naissance' => ['required', 'date_format:Y-m-d', 'before:today', 'after:1920-01-01'],
        ];
    }

    public function messages(): array
    {
        return [
            'cin.required' => 'Veuillez saisir un numéro de CIN.',
            'cin.regex' => 'Le numéro de CIN doit être composé d\'exactement 8 chiffres (chiffres 0 à 9 uniquement, sans lettre, espace ou tiret).',
            'date_naissance.required' => 'Veuillez saisir votre date de naissance pour retrouver vos informations.',
            'date_naissance.date_format' => 'La date de naissance doit être au format AAAA-MM-JJ (ex : 2004-03-15).',
            'date_naissance.before' => 'La date de naissance doit être antérieure à aujourd\'hui.',
            'date_naissance.after' => 'La date de naissance doit être postérieure au 01/01/1920.',
        ];
    }
}
