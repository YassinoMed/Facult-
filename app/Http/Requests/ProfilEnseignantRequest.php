<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mise à jour du profil depuis l'Espace Enseignant.
 * Seuls l'email professionnel et le bureau sont modifiables : le
 * matricule, le grade, le département, la spécialité, la faculté et le
 * statut d'activité restent gérés par l'administration (données
 * officielles — toute erreur se signale à la scolarité).
 */
class ProfilEnseignantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return session()->has('enseignant_auth_id');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim($this->email ?? '')),
            'telephone' => trim($this->telephone ?? '') ?: null,
            'bureau' => trim($this->bureau ?? '') ?: null,
        ]);
    }

    public function rules(): array
    {
        $enseignantId = session('enseignant_auth_id');

        return [
            'email' => ['required', 'string', 'email', 'max:254', Rule::unique('teachers', 'email')->ignore($enseignantId)],
            'telephone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9\s\-()]{7,30}$/'],
            'bureau' => 'nullable|string|max:50',
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => "L'adresse email professionnelle est obligatoire.",
            'email.email' => 'Veuillez saisir une adresse email valide.',
            'email.max' => "L'adresse email ne doit pas dépasser 254 caractères.",
            'email.unique' => 'Cette adresse email est déjà utilisée par un autre enseignant.',
            'telephone.max' => 'Le numéro de téléphone ne doit pas dépasser 30 caractères.',
            'telephone.regex' => "Le format du numéro de téléphone n'est pas valide.",
            'bureau.max' => 'Le bureau ne doit pas dépasser 50 caractères.',
        ];
    }
}
