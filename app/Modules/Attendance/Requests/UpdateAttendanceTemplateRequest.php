<?php

namespace App\Modules\Attendance\Requests;

use App\Modules\Attendance\Models\AttendanceTemplate;
use App\Modules\Attendance\Services\AttendanceTemplateBlockValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation serveur stricte pour la mise à jour d'un modèle de présence (§12).
 */
class UpdateAttendanceTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->can('attendance.templates.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var AttendanceTemplate $template */
        $template = $this->route('template');
        $colonnesAutorisees = array_keys(AttendanceTemplate::COLONNES_DISPONIBLES);
        $typesAutorises = array_keys(AttendanceTemplate::TYPES);

        return [
            'name' => ['required', 'string', 'max:150'],
            'code' => [
                'nullable',
                'string',
                'max:60',
                'regex:/^[a-z0-9_-]+$/i',
                Rule::unique('attendance_templates', 'code')->ignore($template->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'type' => ['required', 'string', Rule::in($typesAutorises)],
            'is_active' => ['nullable', 'boolean'],
            'configuration' => ['required', 'array'],
            'configuration.orientation' => ['required', 'string', Rule::in(['portrait', 'landscape'])],
            'configuration.paper' => ['nullable', 'string', Rule::in(['A4', 'A3', 'letter'])],
            'configuration.columns' => ['required', 'array', 'min:1'],
            'configuration.columns.*.key' => ['required', 'string', Rule::in($colonnesAutorisees)],
            'configuration.columns.*.label' => ['required', 'string', 'max:60'],
            'configuration.columns.*.width' => ['nullable', 'string', 'max:20'],
            'configuration.columns.*.visible' => ['nullable', 'boolean'],
            'configuration.options' => ['nullable', 'array'],
            'configuration.blocks' => ['nullable', 'array'],
            'configuration.blocks.*.type' => ['required_with:configuration.blocks', 'string', Rule::in(AttendanceTemplateBlockValidator::TYPES_BLOCS_AUTORISES)],
            'configuration.blocks.*.position' => ['required_with:configuration.blocks', 'integer', 'min:0'],
            'configuration.blocks.*.enabled' => ['required_with:configuration.blocks', 'boolean'],
            'configuration.blocks.*.config' => ['nullable', 'array'],
        ];
    }
}
