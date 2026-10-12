<?php

namespace App\Modules\Sentinel\Requests;

use App\Modules\Sentinel\Models\SentinelIncident;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Platform Sentinel — transition d'incident (cycle de vie serveur).
 */
class TransiterIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('sentinel.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cible' => ['required', 'string', 'in:'.implode(',', SentinelIncident::STATUTS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['cible.in' => 'Statut cible inconnu.'];
    }
}
