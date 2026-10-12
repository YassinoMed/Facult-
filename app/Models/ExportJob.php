<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 17 §17.2 : job d'export (fichier à expiration, URL signée).
 */
class ExportJob extends Model
{
    protected $table = 'export_jobs';

    protected $fillable = [
        'entite', 'filtres', 'colonnes', 'format', 'statut',
        'chemin_fichier', 'expire_le', 'auteur_id',
    ];

    protected function casts(): array
    {
        return ['filtres' => 'array', 'colonnes' => 'array', 'expire_le' => 'datetime'];
    }

    public function estExpire(): bool
    {
        return $this->expire_le !== null && $this->expire_le->isPast();
    }
}
