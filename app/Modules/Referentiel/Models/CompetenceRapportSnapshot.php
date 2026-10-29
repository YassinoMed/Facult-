<?php

namespace App\Modules\Referentiel\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot IMMUABLE d'un rapport de couverture pédagogique (Lot 20).
 *
 * Les associations compétence ↔ programme/UE/ECUE restent canoniques et
 * non versionnées (choix MVP) : ce snapshot est la trace figée d'un
 * rapport publié. Aucune mise à jour, aucune suppression : une nouvelle
 * capture crée une nouvelle ligne, jamais une réinterprétation.
 */
class CompetenceRapportSnapshot extends Model
{
    protected $table = 'competence_rapport_snapshots';

    public $timestamps = false;

    protected $fillable = [
        'maquette_id', 'empreinte', 'donnees', 'cree_par', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'donnees' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function maquette(): BelongsTo
    {
        return $this->belongsTo(Maquette::class);
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }
}
