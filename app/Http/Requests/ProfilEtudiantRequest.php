<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation de la mise à jour des coordonnées depuis l'Espace Étudiant.
 * Seuls les champs autorisés sont validés : les identifiants (CIN, n° inscription),
 * l'affectation universitaire, la date de naissance (facteur d'identité du
 * parcours « dossier existant ») et le statut restent gérés par
 * l'administration. L'email n'est validé ici que pour être CONFRONTÉ à
 * l'adresse enregistrée : son changement exige le parcours vérifié à double
 * code (LOOP-SECURITY-REMEDIATION-01).
 */
class ProfilEtudiantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return session()->has('etudiant_auth_id');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim($this->email ?? '')),
            'telephone' => trim($this->telephone ?? ''),
            'adresse' => trim($this->adresse ?? ''),
            'ville' => trim($this->ville ?? ''),
            'code_postal' => trim($this->code_postal ?? ''),
        ]);
    }

    public function rules(): array
    {
        $etudiantId = session('etudiant_auth_id');

        return [
            'email' => ['required', 'string', 'email', 'max:254', Rule::unique('etudiants', 'email')->ignore($etudiantId)],
            'telephone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9\s\-()]{7,30}$/'],
            'adresse' => 'required|string|min:5|max:500',
            'ville' => 'required|string|min:2|max:100',
            'code_postal' => 'required|string|max:20',
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'L\'adresse email est obligatoire.',
            'email.email' => 'Veuillez saisir une adresse email valide.',
            'email.max' => 'L\'adresse email ne doit pas dépasser 254 caractères.',
            'email.unique' => 'Cette adresse email est déjà utilisée par un autre étudiant.',
            'telephone.max' => 'Le numéro de téléphone ne doit pas dépasser 30 caractères.',
            'telephone.regex' => 'Le format du numéro de téléphone n\'est pas valide.',
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
