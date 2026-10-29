<?php

namespace App\Modules\ParcoursAcademique\Requests;

use App\Modules\ParcoursAcademique\Models\ParcoursSimulation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Simulation SELF-SERVICE (portail étudiant, ADVANCED-01 LOT B).
 *
 * Contrairement à StoreSimulationRequest (back-office), AUCUN
 * `etudiant_id`, `inscription_source_id` ni auteur n'est accepté
 * depuis le client : l'étudiant est résolu exclusivement depuis la
 * session serveur (`etudiant.auth`) et la source d'inscription est
 * la dernière inscription réelle de l'étudiant.
 */
class MaSimulationParcoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // identité garantie par le middleware `etudiant.auth`.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scenario' => ['required', 'string', 'in:'.implode(',', ParcoursSimulation::SCENARIOS)],
            'hypotheses' => ['nullable', 'array'],
            'hypotheses.note' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'hypotheses.matiere_id' => ['nullable', 'integer', 'exists:matieres,id'],
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
            'hypotheses.note.min' => 'La note fictive doit être entre 0 et 20.',
            'hypotheses.note.max' => 'La note fictive doit être entre 0 et 20.',
        ];
    }
}
