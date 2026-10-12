<?php

namespace App\Modules\Schedule\Requests;

use App\Modules\Schedule\Models\ScheduleException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Exception ponctuelle : date future ou du jour, heures cohérentes si
 * déplacé, motif recommandé.
 */
class StoreScheduleExceptionRequest extends FormRequest
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
            'exception_date' => 'required|date|after_or_equal:today',
            'new_start_time' => 'nullable|date_format:H:i',
            'new_end_time' => 'nullable|date_format:H:i|after:new_start_time',
            'new_room_id' => 'nullable|exists:rooms,id',
            'new_teacher_id' => 'nullable|exists:teachers,id',
            'status' => ['required', Rule::in(ScheduleException::STATUTS)],
            'reason' => 'nullable|string|max:500',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'exception_date.after_or_equal' => 'La date doit être aujourd’hui ou dans le futur.',
            'new_end_time.after' => 'L’heure de fin doit être postérieure à l’heure de début.',
        ];
    }
}
