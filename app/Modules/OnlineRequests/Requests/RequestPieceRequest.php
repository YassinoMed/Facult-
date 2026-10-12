<?php

namespace App\Modules\OnlineRequests\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Demande de pièce back-office : commentaire transmis à l'étudiant
 * (transition in_review → waiting_student).
 */
class RequestPieceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->hasPermission('demandes.request_documents');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['commentaire' => 'required|string|max:500'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['commentaire.required' => 'Précisez la pièce attendue.'];
    }
}
