<?php

namespace App\Modules\Evaluation\Requests;

use App\Modules\Evaluation\Models\SessionExamen;
use Illuminate\Foundation\Http\FormRequest;

class StoreSessionExamenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('sessions_examen.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'annee_universitaire_id' => ['required', 'exists:annees_universitaires,id'],
            'session_origine_id' => ['nullable', 'exists:evaluation_sessions,id'],
            'code' => ['required', 'string', 'max:30'],
            'nom' => ['required', 'string', 'max:150'],
            'type' => ['required', 'in:'.implode(',', SessionExamen::TYPES)],
            'date_debut' => ['required', 'date'],
            'date_fin' => ['required', 'date', 'after_or_equal:date_debut'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator($validateur): void
    {
        // Phase 5 §43 : un rattrapage est toujours rattaché à sa
        // principale (même année) ; les autres types n'ont pas d'origine.
        $validateur->after(function ($v) {
            $type = (string) $this->input('type', '');
            $origineId = $this->input('session_origine_id');

            if ($type === 'rattrapage') {
                if ($origineId === null || $origineId === '') {
                    $v->errors()->add('session_origine_id', 'Un rattrapage exige sa session principale d’origine.');
                } else {
                    $origine = SessionExamen::find($origineId);

                    if ($origine === null || (int) $origine->annee_universitaire_id !== (int) $this->input('annee_universitaire_id')) {
                        $v->errors()->add('session_origine_id', 'L’origine doit appartenir à la même année universitaire.');
                    } elseif ($origine->type !== 'principale') {
                        $v->errors()->add('session_origine_id', 'L’origine d’un rattrapage est une session principale.');
                    }
                }
            } elseif ($origineId !== null && $origineId !== '') {
                $v->errors()->add('session_origine_id', 'Seul un rattrapage possède une session d’origine.');
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'date_fin.after_or_equal' => 'La fin de session doit suivre son début.',
        ];
    }
}
