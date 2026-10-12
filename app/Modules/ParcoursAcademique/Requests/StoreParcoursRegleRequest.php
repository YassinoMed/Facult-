<?php

namespace App\Modules\ParcoursAcademique\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Règles du parcours : code versionné + critères JSON.
 */
class StoreParcoursRegleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('parcours.regles');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:80'],
            'libelle' => ['required', 'string', 'max:200'],
            'annee_universitaire_id' => ['nullable', 'integer', 'exists:annees_universitaires,id'],
            'programme_id' => ['nullable', 'integer', 'exists:programmes,id'],
            'criteres' => ['nullable', 'array'],
            'criteres_json' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
