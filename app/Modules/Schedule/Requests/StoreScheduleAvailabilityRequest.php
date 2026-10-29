<?php

namespace App\Modules\Schedule\Requests;

use App\Modules\Schedule\Models\ScheduleAvailability;
use App\Modules\Schedule\Models\ScheduleSlot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Déclaration d'une disponibilité/indisponibilité : champs stricts,
 * plage cohérente et EXACTEMENT une ressource (enseignant, salle ou
 * groupe). Le chevauchement est contrôlé dans le service.
 */
class StoreScheduleAvailabilityRequest extends FormRequest
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
            'annee_universitaire_id' => 'nullable|exists:annees_universitaires,id',
            'teacher_id' => 'nullable|exists:teachers,id',
            'room_id' => 'nullable|exists:rooms,id',
            'group_id' => 'nullable|exists:groups,id',
            'day_of_week' => ['required', Rule::in(ScheduleSlot::JOURS)],
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'type' => ['required', Rule::in(ScheduleAvailability::TYPES)],
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'reason' => 'nullable|string|max:500',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $ressources = collect(['teacher_id', 'room_id', 'group_id'])
                ->filter(fn (string $colonne) => filled($this->input($colonne)));

            if ($ressources->count() !== 1) {
                $v->errors()->add(
                    'teacher_id',
                    'Déclarez exactement une ressource : un enseignant, une salle ou un groupe.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'end_time.after' => 'L’heure de fin doit être postérieure à l’heure de début.',
            'day_of_week.in' => 'Jour invalide.',
            'effective_to.after_or_equal' => 'La fin de période doit être postérieure ou égale au début.',
        ];
    }
}
