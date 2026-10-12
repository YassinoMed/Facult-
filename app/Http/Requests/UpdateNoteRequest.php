<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Correction d'une note — le motif est fortement recommandé et
 * journalisé avec l'ancienne et la nouvelle valeur.
 */
class UpdateNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('notes.update') ?? false;
    }

    public function rules(): array
    {
        return [
            'note' => 'required|numeric|min:0|max:20',
            'observation' => 'nullable|string|max:500',
            'raison' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'note.required' => 'La nouvelle note est obligatoire.',
            'note.numeric' => 'La note doit être une valeur numérique.',
            'note.min' => 'La note ne peut pas être inférieure à 0.',
            'note.max' => 'La note ne peut pas être supérieure à 20.',
            'observation.max' => 'L\'observation ne doit pas dépasser 500 caractères.',
            'raison.max' => 'Le motif de correction ne doit pas dépasser 500 caractères.',
        ];
    }
}
