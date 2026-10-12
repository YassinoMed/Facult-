<?php

namespace App\Modules\Schedule\Requests;

use App\Modules\Schedule\Services\ScheduleImportService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload d'import (§10/§26) : extension parmi les importers du module,
 * 10 Mo max. Contenu re-validé en profondeur dans le service.
 */
class ImportScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->hasPermission('schedules.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fichier' => 'required|file|max:10240|mimetypes:text/plain,text/csv,application/csv,application/json,application/xml,text/xml,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip',
            'annee_universitaire_id' => 'nullable|exists:annees_universitaires,id',
            'semestre' => 'nullable|in:1,2',
            'groupe_id' => 'nullable|exists:groups,id',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fichier.required' => 'Le fichier est obligatoire.',
            'fichier.max' => 'Fichier trop volumineux (maximum 10 Mo).',
            'fichier.mimetypes' => 'Formats acceptés : CSV, JSON, XML, XLSX (contenu vérifié).',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->hasFile('fichier')) {
                return;
            }
            // L'extension réelle (serveur) décide du format accepté.
            $extension = mb_strtolower($this->file('fichier')->getClientOriginalExtension() ?: '');

            if (! in_array($extension, explode(',', ScheduleImportService::formats()), true)) {
                $validator->errors()->add('fichier', 'Formats acceptés : CSV, JSON, XML, XLSX.');
            }
        });
    }
}
