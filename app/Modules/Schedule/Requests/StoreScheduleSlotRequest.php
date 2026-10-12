<?php

namespace App\Modules\Schedule\Requests;

use App\Modules\Schedule\Models\ScheduleSlot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Créneau : champs stricts + cohérence horaire. Les conflits
 * (groupe/enseignant/salle) sont vérifiés dans le service.
 */
class StoreScheduleSlotRequest extends FormRequest
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
            'matiere_id' => 'required|exists:matieres,id',
            'teacher_id' => 'nullable|exists:teachers,id',
            'room_id' => 'nullable|exists:rooms,id',
            'day_of_week' => ['required', Rule::in(ScheduleSlot::JOURS)],
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'type' => ['required', Rule::in(ScheduleSlot::TYPES)],
            'notes' => 'nullable|string|max:500',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'end_time.after' => 'L’heure de fin doit être postérieure à l’heure de début.',
            'day_of_week.in' => 'Jour invalide.',
        ];
    }
}
