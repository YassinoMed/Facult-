<?php

namespace App\Modules\Calendar\Requests;

use App\Modules\Calendar\Models\EvenementCalendrier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEvenementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('calendriers.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'annee_universitaire_id' => ['required', 'exists:annees_universitaires,id'],
            'titre' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(EvenementCalendrier::TYPES)],
            'date_debut' => ['required', 'date'],
            'date_fin' => ['nullable', 'date', 'after_or_equal:date_debut'],
            'heure' => ['nullable', 'date_format:H:i'],
            'description' => ['nullable', 'string', 'max:2000'],
            'lieu' => ['nullable', 'string', 'max:150'],
            'audience' => ['required', Rule::in(EvenementCalendrier::AUDIENCES)],
            'faculte_id' => ['nullable', 'exists:facultes,id'],
            'programme_id' => ['nullable', 'exists:programmes,id'],
            'groupe_id' => ['nullable', 'exists:groups,id'],
        ];
    }
}
