<?php

namespace App\Modules\Evaluation\Requests;

use App\Modules\Evaluation\Models\EvaluationIncident;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('evaluations.update') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(EvaluationIncident::TYPES)],
            'description' => ['required', 'string', 'max:2000'],
            'etudiant_id' => ['nullable', 'integer', 'exists:etudiants,id'],
        ];
    }
}
