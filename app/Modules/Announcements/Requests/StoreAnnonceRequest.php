<?php

namespace App\Modules\Announcements\Requests;

use App\Modules\Announcements\Models\Annonce;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAnnonceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('annonces.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'titre' => ['required', 'string', 'max:150'],
            'contenu' => ['required', 'string', 'max:5000'],
            'type' => ['required', Rule::in(Annonce::TYPES)],
            'priorite' => ['nullable', 'integer', 'min:0', 'max:3'],
            'epinglee' => ['nullable', 'boolean'],
            'expire_a' => ['nullable', 'date', 'after:now'],
            'lien' => ['nullable', 'url', 'max:255'],
            'audience' => ['required', Rule::in(Annonce::AUDIENCES)],
            'faculte_id' => ['nullable', 'exists:facultes,id'],
            'programme_id' => ['nullable', 'exists:programmes,id'],
            'groupe_id' => ['nullable', 'exists:groups,id'],
        ];
    }
}
