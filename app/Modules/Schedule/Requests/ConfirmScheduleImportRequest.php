<?php

namespace App\Modules\Schedule\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirmation d'import (§11/§17) : mode + token de prévisualisation.
 * Le remplacement exige une confirmation explicite (jamais auto).
 */
class ConfirmScheduleImportRequest extends FormRequest
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
            'token' => 'required|string|size:36',
            'mode' => 'required|in:creer,remplacer',
            // `accepted` seul échouerait même en mode création (champ
            // absent) : on exclut le champ sauf en remplacement, où il
            // devient obligatoire.
            'confirmation' => 'exclude_unless:mode,remplacer|required|accepted',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mode.in' => 'Mode inconnu (création ou remplacement).',
            'confirmation.accepted' => 'Le remplacement exige une confirmation explicite.',
        ];
    }
}
