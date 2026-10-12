<?php

namespace App\Modules\Schedule\Requests;

use App\Modules\Schedule\Models\ScheduleSlot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Déplacement de créneau (drag & drop / modale) : jour + horaires +
 * salle. Mêmes gardes que la modification + périmètre faculté sur le
 * créneau ciblée (le navigateur ne fait jamais foi).
 */
class DeplacerScheduleSlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user() === null || ! $this->user()->hasPermission('schedules.manage')) {
            return false;
        }

        $slot = $this->route('slot');

        // Périmètre : 404 (pas 403) pour ne pas confirmer l'existence
        // d'un créneau hors périmètre.
        if ($slot !== null && ! $this->user()->peutAccederFaculte((int) $slot->schedule->groupe->faculte_id)) {
            throw new NotFoundHttpException;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'day_of_week' => ['required', Rule::in(ScheduleSlot::JOURS)],
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'room_id' => 'nullable|exists:rooms,id',
            'motif' => 'nullable|string|max:500',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('start_time') >= $this->input('end_time')) {
                $validator->errors()->add('end_time', 'L’heure de fin doit être postérieure à l’heure de début.');
            }
        });
    }
}
