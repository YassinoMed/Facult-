<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation de la mise à jour du dossier via le parcours public
 * « dossier existant » (sans login, après vérification d'identité).
 *
 * Règles (§3.3) :
 * - SEULS les champs modifiables sont validés : tout champ absent de
 *   ces règles (statut, cin, nom, prenom, email, sexe, date de
 *   naissance, faculté, programme, niveau, année) est IGNORÉ, même
 *   s'il figure dans la requête HTTP — jamais accepté « parce qu'il
 *   est là » ;
 * - aucune mise à jour massive : le contrôleur revalide côté serveur
 *   et ne retient que la whitelist du service canonique ;
 * - règles de format alignées sur ProfilEtudiantRequest (Espace
 *   Étudiant) : une seule source de vérité pour la validation des
 *   coordonnées ;
 * - l'email n'est PAS modifiable par cette route (seule la popup
 *   dédiée `/inscription/existant/email*`, avec double preuve, peut
 *   le modifier — cf. `DossierExistantEmailRequest`).
 */
class DossierExistantModificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // L'autorisation réelle (jeton temporaire lié au dossier) est
        // vérifiée par le contrôleur à partir de la session.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'telephone' => trim($this->telephone ?? ''),
            'adresse' => trim($this->adresse ?? ''),
            'ville' => trim($this->ville ?? ''),
            'code_postal' => trim($this->code_postal ?? ''),
        ]);
    }

    public function rules(): array
    {
        return [
            'telephone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9\s\-()]{7,30}$/'],
            'adresse' => 'required|string|min:5|max:500',
            'ville' => 'required|string|min:2|max:100',
            'code_postal' => 'required|string|max:20',
            'confirmation' => ['sometimes', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirmation.accepted' => 'Vous devez certifier sur l\'honneur l\'exactitude des renseignements fournis.',
            'telephone.max' => 'Le numéro de téléphone ne doit pas dépasser 30 caractères.',
            'telephone.regex' => 'Le numéro de téléphone saisi n\'est pas valide.',
            'adresse.required' => 'L\'adresse est obligatoire.',
            'adresse.min' => 'L\'adresse doit contenir au moins 5 caractères.',
            'adresse.max' => 'L\'adresse ne doit pas dépasser 500 caractères.',
            'ville.required' => 'La ville est obligatoire.',
            'ville.min' => 'La ville doit contenir au moins 2 caractères.',
            'ville.max' => 'La ville ne doit pas dépasser 100 caractères.',
            'code_postal.required' => 'Le code postal est obligatoire.',
            'code_postal.max' => 'Le code postal ne doit pas dépasser 20 caractères.',
        ];
    }
}
