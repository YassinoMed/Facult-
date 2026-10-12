<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMatiereRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('configuration.manage') ?? false;
    }

    /**
     * INV-4 (audit 08/10/2026) : normalisation identique à la création.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => mb_strtoupper(trim((string) $this->input('code'))),
        ]);
    }

    public function rules(): array
    {
        $matiereId = $this->route('matiere')?->id ?? $this->route('matiere');

        return [
            'code' => [
                'required',
                'string',
                'max:20',
                // Tiret autorisé : les codes réels en comportent (DEMO-ALG).
                'regex:/^[A-Z0-9-]{2,20}$/',
                Rule::unique('matieres', 'code')->ignore($matiereId),
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
        return (new StoreMatiereRequest)->messages();
    }
}
