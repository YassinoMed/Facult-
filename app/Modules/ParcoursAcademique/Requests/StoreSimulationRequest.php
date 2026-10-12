<?php

namespace App\Modules\ParcoursAcademique\Requests;

use App\Modules\ParcoursAcademique\Models\ParcoursSimulation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Simulation : authorize = permission + scope vérifiés
 * dans le contrôleur (404 avant 403). Règles strictes FR.
 */
class StoreSimulationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('parcours.simuler');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'etudiant_id' => ['required', 'integer', 'exists:etudiants,id'],
            'inscription_source_id' => ['nullable', 'integer', 'exists:inscriptions_academiques,id'],
            'scenario' => ['required', 'string', 'in:'.implode(',', ParcoursSimulation::SCENARIOS)],
            'hypotheses' => ['nullable', 'array'],
            'hypotheses.matiere_id' => ['nullable', 'integer', 'exists:matieres,id'],
            'hypotheses.note' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'hypotheses.semestre' => ['nullable', 'integer', 'in:1,2'],
            'hypotheses.cible' => ['nullable', 'string', 'max:200'],
            'hypotheses.cible_libelle' => ['nullable', 'string', 'max:200'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'scenario.in' => 'Scénario inconnu.',
            'hypotheses.note.max' => 'La note fictive doit être entre 0 et 20.',
        ];
    }
}
