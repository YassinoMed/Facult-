<?php

namespace App\Modules\Alumni\Models;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\TypeDiplome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Diplôme DÉLIVRÉ — registre officiel.
 *
 * Cette table n'est jamais alimentée par un calcul (notes, crédits,
 * délibération) : seul l'import officiel (CSV/API) écrit une ligne.
 * L'identité n'est pas dupliquée : elle reste dans `etudiants`.
 */
class Diplome extends Model
{
    protected $table = 'diplomes';

    public const SOURCES = ['csv', 'api'];

    protected $fillable = [
        'numero_diplome', 'etudiant_id', 'faculte_id', 'programme_id',
        'type_diplome_id', 'date_delivrance', 'mention', 'source',
        'reference_import', 'importe_le',
    ];

    protected function casts(): array
    {
        return [
            'date_delivrance' => 'date',
            'importe_le' => 'datetime',
        ];
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function faculte(): BelongsTo
    {
        return $this->belongsTo(Faculte::class);
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function typeDiplome(): BelongsTo
    {
        return $this->belongsTo(TypeDiplome::class);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(ImportDiplomes::class, 'reference_import', 'reference');
    }

    /**
     * Périmètre facultaire : un acteur restreint ne voit que sa faculté.
     */
    public function scopePortee(Builder $query, ?int $faculteId): Builder
    {
        return $query->when($faculteId !== null, fn (Builder $q) => $q->where('faculte_id', $faculteId));
    }
}
