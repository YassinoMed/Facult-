<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMatiereRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('configuration.manage') ?? false;
    }

    /**
     * INV-4 (audit 08/10/2026) : le code est normalisé AVANT les règles —
     * l'unicité (case-sensitive côté SQLite) doit s'appliquer au code
     * canonique, sinon « inf301 » passerait la validation puis ferait
     * échouer l'INSERT (unique sur « INF301 » après hook du modèle).
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => mb_strtoupper(trim((string) $this->input('code'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:20',
                // Tiret autorisé : les codes réels en comportent (DEMO-ALG).
                'regex:/^[A-Z0-9-]{2,20}$/',
                Rule::unique('matieres', 'code'),
            ],
            'nom' => 'required|string|max:150',
            'coefficient' => 'required|numeric|gt:0|max:99',
            'credits' => 'required|integer|min:0|max:60',
            'niveau' => 'required|in:L1,L2,L3,M1,M2',
            'filiere' => 'nullable|string|max:150',
            'semestre' => 'required|integer|in:1,2',
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Le code de la matière est obligatoire.',
            'code.regex' => 'Le code doit être en majuscules (2 à 20 caractères alphanumériques).',
            'code.unique' => 'Ce code matière est déjà utilisé.',
            'nom.required' => 'Le nom de la matière est obligatoire.',
            'nom.max' => 'Le nom ne doit pas dépasser 150 caractères.',
            'coefficient.required' => 'Le coefficient est obligatoire.',
            'coefficient.gt' => 'Le coefficient doit être strictement positif.',
            'coefficient.max' => 'Le coefficient ne peut pas dépasser 99.',
            'credits.required' => 'Le nombre de crédits est obligatoire.',
            'credits.min' => 'Les crédits ne peuvent pas être négatifs.',
            'credits.max' => 'Les crédits ne peuvent pas dépasser 60.',
            'niveau.required' => 'Le niveau est obligatoire.',
            'niveau.in' => 'Le niveau doit être L1, L2, L3, M1 ou M2.',
            'semestre.required' => 'Le semestre est obligatoire.',
            'semestre.in' => 'Le semestre doit être 1 ou 2.',
        ];
    }
}
