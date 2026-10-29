<?php

namespace App\Modules\OnlineRequests\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rejet back-office : motif obligatoire (transmis à l'étudiant).
 */
class RejectOnlineRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        // LOT 01 : Gate (RBAC + délégation active de l'autorité de validation).
        return $this->user() !== null && $this->user()->can('demandes.reject');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['motif' => 'required|string|max:500'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['motif.required' => 'Le motif du rejet est obligatoire.'];
    }
}
