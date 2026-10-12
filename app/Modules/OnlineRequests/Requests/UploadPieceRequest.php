<?php

namespace App\Modules\OnlineRequests\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pièce justificative étudiante : fichier + ownership vérifiée en
 * contrôleur (jamais depuis un ID client libre).
 */
class UploadPieceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return session()->has('etudiant_auth_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var array<int, string> $extensions */
        $extensions = config('online-requests.extensions', []);

        return [
            'piece' => [
                'required',
                'file',
                'max:'.((int) config('online-requests.taille_max', 10 * 1024 * 1024) / 1024),
                'mimes:'.implode(',', $extensions),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'piece.required' => 'Le fichier est obligatoire.',
            'piece.max' => 'Fichier trop volumineux (maximum 10 Mo).',
            'piece.mimes' => 'Formats acceptés : PDF, JPG, PNG, WebP.',
        ];
    }
}
