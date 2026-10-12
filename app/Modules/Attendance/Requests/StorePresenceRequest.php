<?php

namespace App\Modules\Attendance\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Module Attendance : validation forte côté serveur (§31). L'étudiant
 * cible est résolu et scopé dans le contrôleur, jamais depuis une
 * relation client.
 */
class StorePresenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->hasPermission('attendance.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'etudiant_id' => 'required|exists:etudiants,id',
            'date' => 'required|date|before_or_equal:today',
            'seance' => 'nullable|string|max:50',
            'statut' => 'required|in:present,absent,retard,excuse',
            'motif' => 'nullable|string|max:500',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'etudiant_id.required' => "L'étudiant est obligatoire.",
            'etudiant_id.exists' => "L'étudiant sélectionné est introuvable.",
            'date.required' => 'La date de la séance est obligatoire.',
            'date.before_or_equal' => 'La date ne peut pas être dans le futur.',
            'statut.required' => 'Le statut de présence est obligatoire.',
            'statut.in' => 'Le statut sélectionné est invalide.',
        ];
    }
}
