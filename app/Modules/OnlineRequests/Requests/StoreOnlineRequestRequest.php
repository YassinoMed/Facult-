<?php

namespace App\Modules\OnlineRequests\Requests;

use App\Modules\OnlineRequests\Models\RequestType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Création étudiante : type actif, année existante, objet/description.
 * Ni statut, ni référence, ni responsable — serveur uniquement.
 */
class StoreOnlineRequestRequest extends FormRequest
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
        return [
            'request_type_id' => 'required|exists:request_types,id',
            'annee_universitaire_id' => 'nullable|exists:annees_universitaires,id',
            'objet' => 'required|string|max:150',
            'description' => 'nullable|string|max:2000',
            'form_data' => 'nullable|array',
            'confirmation' => 'accepted',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'objet' => trim($this->objet ?? ''),
            'description' => $this->description !== null ? trim($this->description) : null,
        ]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $type = RequestType::find($this->input('request_type_id'));

            if ($type !== null && ! $type->active) {
                $validator->errors()->add('request_type_id', 'Ce type de demande est désactivé.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'request_type_id.required' => 'Le type de demande est obligatoire.',
            'objet.required' => 'L’objet est obligatoire.',
            'confirmation.accepted' => 'Vous devez confirmer l’exactitude des informations.',
        ];
    }
}
