<?php

namespace App\Modules\Schedule\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Création d'un emploi du temps : unicité (groupe, année, semestre)
 * garantie par la contrainte BD + message métier.
 */
class StoreScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->hasPermission('schedules.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'annee_universitaire_id' => 'required|exists:annees_universitaires,id',
            'semestre' => 'required|integer|in:1,2',
            'groupe_id' => 'required|exists:groups,id',
            'nom' => 'required|string|max:150',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'groupe_id.required' => 'Le groupe est obligatoire.',
            'semestre.in' => 'Semestre invalide (1 ou 2).',
        ];
    }
}
