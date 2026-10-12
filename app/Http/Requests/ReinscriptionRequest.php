<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Réinscription simplifiée : année cible + choix explicites
 * (programme/niveau optionnels, sinon proposition suivie) + régime
 * + confirmation. Jamais de niveau inventé côté client : le service
 * confronte le choix à la décision officielle.
 */
class ReinscriptionRequest extends FormRequest
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
            'programme_id' => ['nullable', 'integer', 'exists:programmes,id'],
            'niveau_id' => ['nullable', 'integer', 'exists:niveaux,id'],
            'regime' => ['nullable', 'in:initiale,continue,alternance'],
            'motif' => ['nullable', 'string', 'max:500'],
            'confirmer' => ['required', 'accepted'],
        ];
    }
}
