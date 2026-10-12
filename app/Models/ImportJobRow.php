<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 17 §17.2 : ligne d'import (statut, erreurs structurées,
 * action prévue, diff avant/après).
 */
class ImportJobRow extends Model
{
    protected $table = 'import_job_rows';

    protected $fillable = [
        'import_job_id', 'numero_ligne', 'donnees_brutes', 'donnees_normalisees',
        'statut', 'erreurs', 'avertissements', 'action_prevue', 'diff_avant_apres', 'ligne_corrigee',
    ];

    protected function casts(): array
    {
        return [
            'donnees_brutes' => 'array', 'donnees_normalisees' => 'array',
            'erreurs' => 'array', 'avertissements' => 'array', 'diff_avant_apres' => 'array',
        ];
    }
}
