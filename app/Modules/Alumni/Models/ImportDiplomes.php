<?php

namespace App\Modules\Alumni\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Trace d'un import officiel de diplômes.
 *
 * `fichier_sha256` est unique : rejouer le même fichier retourne la trace
 * existante sans créer un second registre. Le rapport ne contient AUCUNE
 * donnée personnelle : numéro de ligne + code de motif.
 */
class ImportDiplomes extends Model
{
    protected $table = 'imports_diplomes';

    public const STATUTS = ['termine', 'partiel', 'rejete'];

    protected $fillable = [
        'reference', 'fichier_nom', 'fichier_sha256', 'source', 'statut',
        'lignes_total', 'lignes_importees', 'lignes_rejetees', 'rapport',
        'importe_par',
    ];

    protected function casts(): array
    {
        return [
            'lignes_total' => 'integer',
            'lignes_importees' => 'integer',
            'lignes_rejetees' => 'integer',
            'rapport' => 'array',
        ];
    }

    public function diplomes(): HasMany
    {
        return $this->hasMany(Diplome::class, 'reference_import', 'reference');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'importe_par');
    }
}
