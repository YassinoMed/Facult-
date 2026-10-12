<?php

namespace App\Modules\Evaluation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeliberationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('deliberations.prepare') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'annee_universitaire_id' => ['required', 'exists:annees_universitaires,id'],
            'session_id' => ['nullable', 'exists:evaluation_sessions,id'],
            'semestre_lmd_id' => ['nullable', 'exists:semestres_lmd,id'],
            'programme_id' => ['nullable', 'exists:programmes,id'],
            'niveau_id' => ['nullable', 'exists:niveaux,id'],
            'groupe_id' => ['nullable', 'exists:groups,id'],
        ];
    }
}
