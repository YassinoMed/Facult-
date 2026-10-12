<?php

namespace App\Modules\Evaluation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaisieNotesAnonymesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('notes_eval.enter') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fichier' => ['nullable', 'file', 'max:2048'],
            'lignes' => ['nullable', 'array', 'max:2000'],
            'lignes.*.numero' => ['required_with:lignes', 'string', 'regex:/^CMP-\d{6}$/i', 'max:11'],
            'lignes.*.valeur' => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ];
    }
}
