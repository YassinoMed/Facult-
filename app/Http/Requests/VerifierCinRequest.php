<?php

namespace App\Http\Requests;

use App\Support\SaisieIdentification;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation de la recherche de pré-remplissage (audit §P0-4 + LOOP saisies §3) :
 * - CIN : exactement 8 chiffres ASCII (référentiel tunisien, forme
 *   uniquement — zéros initiaux préservés, espaces/lettres/tirets REFUSÉS,
 *   jamais convertis) ;
 * - date de naissance : SECOND FACTEUR obligatoire — le CIN seul
 *   (identifiant semi-public) ne doit jamais suffire à récupérer
 *   les données personnelles d'un étudiant ;
 * - contrôle strict à 4 niveaux : les formulaires publics saisissent
 *   JJ/MM/AAAA (auto-format JS + pattern HTML + pré-contrôle Alpine),
 *   normalisés ici vers Y-m-d (format interne des services). La règle
 *   date délègue au validateur canonique SaisieIdentification::valider
 *   (format, date RÉELLE — 31/02 refusé, jamais dans le futur, âge
 *   18-120 ans révolues).
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
            'date_naissance' => SaisieIdentification::normaliserDateNaissance((string) $this->input('date_naissance', '')),
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
        ];
    }

    public function messages(): array
    {
        return [
            'cin.required' => 'Veuillez saisir un numéro de CIN.',
            'cin.regex' => 'Le numéro de CIN doit être composé d\'exactement 8 chiffres (chiffres 0 à 9 uniquement, sans lettre, espace ou tiret).',
            'date_naissance.required' => 'La date de naissance est obligatoire.',
        ];
    }
}
