<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 17 §17.2 : job d'import (workflow en étapes persistées,
 * reprise possible après fermeture du navigateur).
 */
class ImportJob extends Model
{
    protected $table = 'import_jobs';

    protected $fillable = [
        'entite', 'nom_fichier', 'hash_fichier', 'format', 'encodage',
        'delimiteur', 'mapping', 'options', 'mode', 'statut',
        'total_lignes', 'lignes_valides', 'lignes_erreur', 'lignes_importees',
        'auteur_id', 'confirme_le',
    ];

    protected function casts(): array
    {
        return ['mapping' => 'array', 'options' => 'array', 'confirme_le' => 'datetime'];
    }

    /** @return HasMany<ImportJobRow, $this> */
    public function lignes(): HasMany
    {
        return $this->hasMany(ImportJobRow::class, 'import_job_id');
    }

    /** @return HasMany<ImportChange, $this> */
    public function changements(): HasMany
    {
        return $this->hasMany(ImportChange::class, 'import_job_id');
    }
}
