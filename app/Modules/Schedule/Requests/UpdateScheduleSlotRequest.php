<?php

namespace App\Modules\Schedule\Requests;

use App\Modules\Schedule\Models\ScheduleSlot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Modification de créneau : partiel, mêmes gardes que la création.
 */
class UpdateScheduleSlotRequest extends FormRequest
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
            'matiere_id' => 'sometimes|required|exists:matieres,id',
            'teacher_id' => 'nullable|exists:teachers,id',
            'room_id' => 'nullable|exists:rooms,id',
            'day_of_week' => ['sometimes', 'required', Rule::in(ScheduleSlot::JOURS)],
            'start_time' => 'sometimes|required|date_format:H:i',
            'end_time' => 'sometimes|required|date_format:H:i',
            'type' => ['sometimes', 'required', Rule::in(ScheduleSlot::TYPES)],
            'status' => ['sometimes', 'required', Rule::in(ScheduleSlot::STATUTS)],
            'notes' => 'nullable|string|max:500',
            'motif' => 'nullable|string|max:500',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $debut = $this->input('start_time', $this->route('slot')?->start_time);
            $fin = $this->input('end_time', $this->route('slot')?->end_time);

            if ($debut !== null && $fin !== null && $debut >= $fin) {
                $validator->errors()->add('end_time', 'L’heure de fin doit être postérieure à l’heure de début.');
            }
        });
    }
}
