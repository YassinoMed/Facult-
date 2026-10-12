<?php

namespace App\Modules\Evaluation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaisieNotesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('notes_eval.enter') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lignes' => ['required', 'array', 'max:2000'],
            'lignes.*.compostage' => ['required_without:lignes.*.etudiant_id', 'nullable', 'string', 'max:50'],
            'lignes.*.numero' => ['nullable', 'string', 'max:50'],
            'lignes.*.etudiant_id' => ['required_without:lignes.*.compostage', 'nullable', 'exists:etudiants,id'],
            'lignes.*.valeur' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'lignes.*.commentaire' => ['nullable', 'string', 'max:500'],
            'lignes.*.motif' => ['nullable', 'string', 'max:500'],
        ];
    }
}
