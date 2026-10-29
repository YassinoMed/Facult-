<?php

namespace App\Modules\Evaluation\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEpreuveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('epreuves.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'session_id' => ['sometimes', 'exists:evaluation_sessions,id'],
            'matiere_id' => ['required', 'exists:matieres,id'],
            'ue_id' => ['nullable', 'exists:unites_enseignement,id'],
            'groupe_id' => ['nullable', 'exists:groups,id'],
            'sous_groupe_id' => ['nullable', 'exists:sous_groupes,id'],
            // DM-01 : une salle non active ou à capacité inconnue est
            // inadmissible dès la planification.
            'salle_id' => ['nullable', Rule::exists('rooms', 'id')->where(fn ($q) => $q
                ->where('actif', true)
                ->whereNotNull('capacite')
                ->where('capacite', '>', 0))],
            'date' => ['required', 'date'],
            'heure_debut' => ['required', 'date_format:H:i'],
            'heure_fin' => ['required', 'date_format:H:i', 'after:heure_debut'],
            'duree_minutes' => ['nullable', 'integer', 'min:15', 'max:600'],
            // Phase 5 §26 : type issu du référentiel configurable
            // (jamais de chaîne libre côté planification).
            'type_evaluation' => ['nullable', Rule::exists('evaluation_types_evaluation', 'code')->where('actif', true)],
            'coefficient' => ['nullable', 'numeric', 'gt:0', 'max:99'],
            'bareme' => ['nullable', 'numeric', 'gt:0', 'max:1000'],
            'note_max' => ['nullable', 'numeric', 'gt:0', 'max:1000'],
            'capacite_necessaire' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'heure_fin.after' => 'L’heure de fin doit suivre l’heure de début.',
        ];
    }
}
