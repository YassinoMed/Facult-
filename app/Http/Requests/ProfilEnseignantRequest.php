<?php

namespace App\Http\Requests;

use App\Modules\Schedule\Models\Teacher;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Mise à jour du profil depuis l'Espace Enseignant.
 * Seuls l'email professionnel, le téléphone et le bureau sont modifiables :
 * le matricule, le grade, le département, la spécialité, la faculté et le
 * statut d'activité restent gérés par l'administration (données
 * officielles — toute erreur se signale à la scolarité).
 * Le CHANGEMENT d'email exige en plus le mot de passe actuel : l'email est
 * un identifiant de connexion (LOOP-SECURITY-REMEDIATION-01).
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
        $enseignant = Teacher::find($enseignantId);

        return [
            'email' => ['required', 'string', 'email', 'max:254', Rule::unique('teachers', 'email')->ignore($enseignantId)],
            'telephone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9\s\-()]{7,30}$/'],
            'bureau' => 'nullable|string|max:50',
            /*
             | LOOP-SECURITY-REMEDIATION-01 : l'email professionnel est un
             | IDENTIFIANT de connexion. Le changer exige le mot de passe
             | actuel (preuve du porteur du compte) — sinon une simple
             | session détournée suffirait à détourner le compte (changement
             | d'identifiant puis réinitialisation de mot de passe).
             */
            'current_password' => [
                $this->emailChange($enseignant) ? 'required' : 'nullable',
                'string',
                function (string $attribute, mixed $value, Closure $fail) use ($enseignant): void {
                    if (! $this->emailChange($enseignant)) {
                        return;
                    }

                    if ($enseignant === null || empty($enseignant->password) || ! Hash::check((string) $value, $enseignant->password)) {
                        $fail("Le mot de passe actuel est incorrect : il est exigé pour changer l'adresse email.");
                    }
                },
            ],
        ];
    }

    /**
     * L'email soumis diffère-t-il de l'email enregistré ?
     */
    private function emailChange(?Teacher $enseignant): bool
    {
        return $enseignant !== null
            && strtolower(trim((string) $this->email)) !== strtolower((string) $enseignant->email);
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
            'current_password.required' => "Le mot de passe actuel est exigé pour changer l'adresse email.",
        ];
    }
}
