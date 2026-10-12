<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAnneeUniversitaireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('configuration.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'libelle' => [
                'required',
                'string',
                'max:20',
                'regex:/^\d{4}-\d{4}$/',
                Rule::unique('annees_universitaires', 'libelle'),
            ],
            'date_debut' => ['required', 'date_format:Y-m-d'],
            'date_fin' => ['required', 'date_format:Y-m-d', 'after:date_debut'],
            'active' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'libelle.required' => 'Le libellé de l\'année universitaire est obligatoire.',
            'libelle.regex' => 'Le format attendu est « AAAA-AAAA » (ex : 2026-2027).',
            'libelle.unique' => 'Cette année universitaire existe déjà.',
            'date_debut.required' => 'La date de début est obligatoire.',
            'date_debut.date_format' => 'La date de début doit être au format AAAA-MM-JJ.',
            'date_fin.required' => 'La date de fin est obligatoire.',
            'date_fin.date_format' => 'La date de fin doit être au format AAAA-MM-JJ.',
            'date_fin.after' => 'La date de fin doit être postérieure à la date de début.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['active' => $this->boolean('active')]);
    }
}
