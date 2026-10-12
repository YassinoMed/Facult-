<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 17 §17.3 : trace avant/après pour rollback (refusé si
 * dépendances : documents émis, notes verrouillées…).
 */
class ImportChange extends Model
{
    protected $table = 'import_changes';

    protected $fillable = [
        'import_job_id', 'entite', 'cle_metier', 'action', 'avant', 'apres', 'annule_le',
    ];

    protected function casts(): array
    {
        return ['avant' => 'array', 'apres' => 'array', 'annule_le' => 'datetime'];
    }
}
