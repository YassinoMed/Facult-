<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Périodes d'inscription (LOOP) : portée + fenêtre temporelle. Le motif
 * est obligatoire pour toute exception (justification Tracée, jamais
 * null quand la politique l'exige).
 */
class PeriodeInscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'annee_universitaire_id' => ['required', 'integer', 'exists:annees_universitaires,id'],
            'faculte_id' => ['nullable', 'integer', 'exists:facultes,id'],
            'programme_id' => ['nullable', 'integer', 'exists:programmes,id'],
            'niveau' => ['nullable', 'string', 'max:10'],
            'type_inscription' => ['nullable', 'in:premiere,reinscription'],
            'type_periode' => ['required', 'in:normale,exception'],
            'nom' => ['nullable', 'string', 'max:150'],
            'etudiant_id' => ['nullable', 'integer', 'exists:etudiants,id'],
            'opened_at' => ['required', 'date'],
            'closed_at' => ['required', 'date', 'after:opened_at'],
            'motif' => ['nullable', 'string', 'max:2000', 'required_if:type_periode,exception'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'motif.required_if' => 'Ouverture exceptionnelle refusée : motif obligatoire.',
            'closed_at.after' => 'Fermeture antérieure à l’ouverture.',
        ];
    }
}
