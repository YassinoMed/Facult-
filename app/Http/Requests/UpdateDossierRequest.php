<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDossierRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! ($this->user()?->hasPermission('archives.update') ?? false)) {
            return false;
        }

        $dossier = $this->route('dossier');

        return $dossier === null
            || $this->user()->peutAccederFaculte((int) $dossier->etudiant->faculte_id);
    }

    public function rules(): array
    {
        return [
            'statut' => 'required|in:actif,clos',
            'observation' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'statut.required' => 'Le statut du dossier est obligatoire.',
            'statut.in' => 'Le statut doit être « actif » ou « clos ».',
            'observation.max' => 'L\'observation ne doit pas dépasser 1000 caractères.',
        ];
    }
}
