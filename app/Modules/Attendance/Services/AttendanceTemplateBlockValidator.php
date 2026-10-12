<?php

namespace App\Modules\Attendance\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Service de validation stricte des blocs du Builder de présence (§4, §5, §17).
 * Protection absolue contre les injections HTML/PHP/Blade et valeurs non autorisées.
 */
class AttendanceTemplateBlockValidator
{
    public const TYPES_BLOCS_AUTORISES = [
        'header',
        'metadata',
        'student_table',
        'statistics',
        'signature',
        'qr',
        'footer',
    ];

    /**
     * Valide et normalise une liste de blocs pour un modèle.
     *
     * @param  array<mixed>  $blocs
     * @return array<int, array{type: string, position: int, enabled: bool, config: array<string, mixed>}>
     *
     * @throws ValidationException
     */
    public function validerBlocs(array $blocs): array
    {
        $validateur = Validator::make(['blocks' => $blocs], [
            'blocks' => ['required', 'array', 'min:1'],
            'blocks.*.type' => ['required', 'string', Rule::in(self::TYPES_BLOCS_AUTORISES)],
            'blocks.*.position' => ['required', 'integer', 'min:0'],
            'blocks.*.enabled' => ['required', 'boolean'],
            'blocks.*.config' => ['nullable', 'array'],
        ]);

        if ($validateur->fails()) {
            throw new ValidationException($validateur);
        }

        $blocsNormalises = [];
        $index = 1;

        foreach ($blocs as $b) {
            $type = (string) $b['type'];
            $enabled = (bool) ($b['enabled'] ?? true);
            $rawConfig = (array) ($b['config'] ?? []);

            $config = $this->validerEtNettoyerConfig($type, $rawConfig);

            $blocsNormalises[] = [
                'type' => $type,
                'position' => isset($b['position']) ? (int) $b['position'] : $index,
                'enabled' => $enabled,
                'config' => $config,
            ];
            $index++;
        }

        // Réordonner les positions de manière déterministe
        usort($blocsNormalises, fn ($a, $b) => $a['position'] <=> $b['position']);
        foreach ($blocsNormalises as $i => &$bloc) {
            $bloc['position'] = $i + 1;
        }

        return $blocsNormalises;
    }

    /**
     * Valide la configuration spécifique selon le type de bloc.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function validerEtNettoyerConfig(string $type, array $config): array
    {
        return match ($type) {
            'header' => [
                'show_institution' => (bool) ($config['show_institution'] ?? true),
                'show_faculte' => (bool) ($config['show_faculte'] ?? true),
                'show_departement' => (bool) ($config['show_departement'] ?? true),
                'show_reference' => (bool) ($config['show_reference'] ?? true),
                'title_alignment' => in_array($config['title_alignment'] ?? 'right', ['left', 'center', 'right'], true)
                    ? $config['title_alignment']
                    : 'right',
            ],
            'metadata' => [
                'show_groupe' => (bool) ($config['show_groupe'] ?? true),
                'show_matiere' => (bool) ($config['show_matiere'] ?? true),
                'show_enseignant' => (bool) ($config['show_enseignant'] ?? true),
                'show_date' => (bool) ($config['show_date'] ?? true),
                'show_horaire' => (bool) ($config['show_horaire'] ?? true),
                'show_salle' => (bool) ($config['show_salle'] ?? true),
                'show_annee' => (bool) ($config['show_annee'] ?? true),
                'show_effectif' => (bool) ($config['show_effectif'] ?? true),
                'layout' => in_array($config['layout'] ?? 'grid_4', ['grid_2', 'grid_4'], true)
                    ? $config['layout']
                    : 'grid_4',
            ],
            'student_table' => [
                'font_size' => in_array($config['font_size'] ?? '8.5pt', ['7pt', '7.5pt', '8pt', '8.5pt', '9pt', '10pt'], true)
                    ? $config['font_size']
                    : '8.5pt',
                'row_height' => in_array($config['row_height'] ?? 'normal', ['compact', 'normal', 'large'], true)
                    ? $config['row_height']
                    : 'normal',
                'show_matricule' => (bool) ($config['show_matricule'] ?? true),
                'show_cin' => (bool) ($config['show_cin'] ?? false),
                'show_presence' => (bool) ($config['show_presence'] ?? true),
                'show_signature' => (bool) ($config['show_signature'] ?? true),
                'show_observation' => (bool) ($config['show_observation'] ?? true),
            ],
            'statistics' => [
                'show_total' => (bool) ($config['show_total'] ?? true),
                'show_presents' => (bool) ($config['show_presents'] ?? true),
                'show_absents' => (bool) ($config['show_absents'] ?? true),
                'show_retards' => (bool) ($config['show_retards'] ?? true),
                'show_taux' => (bool) ($config['show_taux'] ?? true),
            ],
            'signature' => [
                'type' => in_array($config['type'] ?? 'single', ['single', 'double', 'exam'], true)
                    ? $config['type']
                    : 'single',
                'show_enseignant' => (bool) ($config['show_enseignant'] ?? true),
                'show_administration' => (bool) ($config['show_administration'] ?? true),
                'show_surveillants' => (bool) ($config['show_surveillants'] ?? false),
                'mention_legale' => mb_substr(strip_tags((string) ($config['mention_legale'] ?? 'Document officiel de scolarité.')), 0, 300),
            ],
            'qr' => [
                'position' => in_array($config['position'] ?? 'inline', ['inline', 'header', 'footer'], true)
                    ? $config['position']
                    : 'inline',
                'size' => max(30, min(120, (int) ($config['size'] ?? 60))),
            ],
            'footer' => [
                'show_page_number' => (bool) ($config['show_page_number'] ?? true),
                'show_generation_date' => (bool) ($config['show_generation_date'] ?? true),
                'show_legal_notice' => (bool) ($config['show_legal_notice'] ?? true),
                'custom_notice' => mb_substr(strip_tags((string) ($config['custom_notice'] ?? '')), 0, 200),
            ],
            default => [],
        };
    }
}
