<?php

namespace App\Modules\Evaluation\Requests;

use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use Illuminate\Foundation\Http\FormRequest;

class StoreExamenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('evaluations.create') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('annee_universitaire_id') && $this->filled('session_id')) {
            $session = SessionExamen::find($this->input('session_id'));
            if ($session) {
                $this->merge(['annee_universitaire_id' => $session->annee_universitaire_id]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'session_id' => ['required', 'exists:evaluation_sessions,id'],
            'annee_universitaire_id' => ['required', 'exists:annees_universitaires,id'],
            'semestre_lmd_id' => ['nullable', 'exists:semestres_lmd,id'],
            'programme_id' => ['nullable', 'exists:programmes,id'],
            'niveau_id' => ['nullable', 'exists:niveaux,id'],
            'groupe_id' => ['nullable', 'exists:groups,id'],
            'libelle' => ['required', 'string', 'max:200'],
            'type' => ['required', 'in:'.implode(',', Examen::TYPES)],
            'description' => ['nullable', 'string', 'max:2000'],
            'observations' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $sessionId = $this->input('session_id');
            if ($sessionId && ($session = SessionExamen::find($sessionId))) {
                if ($session->estVerrouillee()) {
                    $validator->errors()->add('session_id', 'Cette session d’examen est verrouillée.');
                }

                if ($this->filled('annee_universitaire_id') && (int) $this->input('annee_universitaire_id') !== (int) $session->annee_universitaire_id) {
                    $validator->errors()->add('annee_universitaire_id', 'L’année universitaire doit être cohérente avec celle de la session d’examen.');
                }
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'session_id.required' => 'La session d’examen est obligatoire.',
            'session_id.exists' => 'La session d’examen sélectionnée est invalide.',
            'annee_universitaire_id.required' => 'L’année universitaire est obligatoire.',
            'annee_universitaire_id.exists' => 'L’année universitaire sélectionnée est invalide.',
            'libelle.required' => 'Le libellé de l’examen est obligatoire.',
            'libelle.max' => 'Le libellé ne peut pas dépasser 200 caractères.',
            'type.required' => 'Le type d’examen est obligatoire.',
            'type.in' => 'Le type d’examen sélectionné est invalide.',
            'programme_id.exists' => 'Le programme sélectionné est invalide.',
            'groupe_id.exists' => 'Le groupe sélectionné est invalide.',
        ];
    }
}
