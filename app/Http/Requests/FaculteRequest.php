<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FaculteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nom' => trim((string) ($this->nom ?? '')),
            'description' => trim((string) ($this->description ?? '')),
            'active' => $this->boolean('active'),
            'code' => strtoupper(trim((string) ($this->code ?? ''))),
        ]);
    }

    public function rules(): array
    {
        $faculte = $this->route('faculte');
        $faculteId = is_object($faculte) ? $faculte->id : $faculte;

        return [
            'nom' => 'required|string|min:2|max:255',
            'code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('facultes', 'code')->ignore($faculteId),
            ],
            'description' => 'nullable|string|max:1000',
            'active' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'nom.required' => 'Le nom de la faculté est obligatoire.',
            'nom.max' => 'Le nom ne doit pas dépasser 255 caractères.',
            'code.required' => 'Le code de la faculté est obligatoire.',
            'code.unique' => 'Ce code est déjà utilisé par une autre faculté.',
            'code.max' => 'Le code ne doit pas dépasser 20 caractères.',
            'description.max' => 'La description ne doit pas dépasser 1000 caractères.',
        ];
    }
}
