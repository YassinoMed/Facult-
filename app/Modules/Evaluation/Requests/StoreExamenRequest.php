<?php

namespace App\Modules\Evaluation\Requests;

use App\Modules\Evaluation\Models\Examen;
use Illuminate\Foundation\Http\FormRequest;

class StoreExamenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('evaluations.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'session_id' => ['required', 'exists:evaluation_sessions,id'],
            'annee_universitaire_id' => ['required', 'exists:annees_universitaires,id'],
            'semestre_lmd_id' => ['nullable', 'exists:semestres_lmd,id'],
            'programme_id' => ['nullable', 'exists:programmes,id'],
            'niveau_id' => ['nullable', 'exists:niveaux,id'],
            'groupe_id' => ['nullable', 'exists:groups,id'],
            'libelle' => ['required', 'string', 'max:200'],
            'type' => ['required', 'in:'.implode(',', Examen::TYPES)],
            'description' => ['nullable', 'string', 'max:2000'],
            'observations' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
